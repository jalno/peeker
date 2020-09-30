<?php
namespace packages\peeker;

use packages\base\{db\MysqliDb, Packages, Log, IO\directory, IO, IO\File, Exception};

class WordpressScript extends Script {
	public static function getPluginInfo(Directory $pluginDir): array {
		$response = array();
		$log = Log::getInstance();
		$log->info("get info about plugin: '{$pluginDir->basename}'");
		$log->debug("get all php files of plugin");
		$files = array_filter($pluginDir->files(false), function ($file) {
			return $file->getExtension() == "php";
		});
		$log->reply(count($files), "file found");
		if (!$files) {
			$log->warn("it seems plugin is damaged, cuz no has any php file!");
			return $response;
		}
		$template = array(
			'name'        => 'Plugin Name',
			'description' => 'Description',
			'version'     => 'Version',
			'path'        => 'Text Domain',
			'pluginURI'   => 'Plugin URI',
			'author'      => 'Author',
			'authorURI'   => 'Author URI',
			'domainPath'  => 'Domain Path',
			'network'     => 'Network',
			'requiresWP'  => 'Requires at least',
			'requiresPHP' => 'Requires PHP',
		);
		foreach ($files as $file) {
			$fileData = $file->read(2048);
			foreach ($template as $field => $regex) {
				if (preg_match('/^[ \t\/*#@]*' . preg_quote($regex, '/') . ':(.*)$/mi', $fileData, $matches) and $matches[1]) {
					$response[$field] = trim($matches[1]);
				}
			}
		}
		return $response;
	}
	/**
	 * @var file
	 */
	protected $config;
	/**
	 * @var MysqliDb
	 */
	protected $db;
	/**
	 * @var array
	 */
	protected $dbInfo;
	public function __construct(File $config) {
		parent::__construct($config->getDirectory());
		$this->config = $config;
	}

	public function getDatabaseInfo() {
		if (!$this->dbInfo) {
			$log = Log::getInstance();
			$log->debug("read wp-config.php");
			$content = $this->config->read();
			$dbInfo = [];
			foreach(['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST'] as $const) {
				$log->debug("looking for", $const);
				if (preg_match("/define\(\s*[\'|\"]{$const}[\'|\"],\s*[\'|\"]([^\"|^\']+)[\'|\"]\s*\);/", $content, $matches)) {
					$log->reply($matches[1]);
					switch($const){
						case('DB_NAME'):$dbInfo['database'] = $matches[1];break;
						case('DB_USER'):$dbInfo['username'] = $matches[1];break;
						case('DB_PASSWORD'):$dbInfo['password'] = $matches[1];break;
						case('DB_HOST'):$dbInfo['host'] = $matches[1];break;
					}
				} else {
					$log->reply()->fatal('Notfound');
					throw new Exception("cannot find ".$const." in wp-config.php");
				}
			}
			$log->debug("looking for \$table_prefix");
			if (preg_match("/\\\$table_prefix\s*=\s*[\'|\"]([^\"|^\']+)[\'|\"];/", $content, $matches)) {
				$log->reply($matches[1]);
				$dbInfo['prefix'] = $matches[1];
			} else {
				$log->reply()->fatal('Notfound');
				throw new Exception("cannot find \$table_prefix in wp-config.php");
			}
			$log->debug("looking for WPLANG");
			if (preg_match("/define\(\s*[\'|\"]WPLANG[\'|\"],\s*[\'|\"]([^\"|^\']+)[\'|\"]\);/", $content, $matches)) {
				$log->reply($matches[1]);
				$this->locale = $matches[1];
			} else {
				$log->reply('Notfound');
			}
			$log->debug("looking for DB_CHARSET");
			if (preg_match("/define\(\s*[\'|\"]DB_CHARSET[\'|\"],\s*[\'|\"]([^\"|^\']+)[\'|\"]\);/", $content, $matches)) {
				$log->reply($matches[1]);
				$dbInfo['charset'] = $matches[1];
			} else {
				$log->reply('Notfound');
			}
			$this->dbInfo = $dbInfo;
		}
		return $this->dbInfo;
	}

	public function setDB(MysqliDb $db) {
		$this->db = $db;
	}

	/**
	 * @return MysqliDb
	 */
	public function requireDB() {
		if (!$this->db) {
			$dbInfo = $this->getDatabaseInfo();
			try {
				$sqlServer = "localhost";
				if ($this->home instanceof Directory\Ftp) {
					$sqlServer = $this->home->getDriver()->getHostname();
				} elseif ($this->home instanceof Directory\SFtp) {
					$sqlServer = $this->home->getDriver()->getSSH()->getHost();
					$hunch = $this->hunchDbInfo();
					if ($hunch) {
						if (isset($hunch['host'])) {
							$sqlServer = $hunch['host'];
						}
						if (isset($hunch['username'])) {
							$dbInfo['username'] = $hunch['username'];
						}
						if (isset($hunch['password'])) {
							$dbInfo['password'] = $hunch['password'];
						}
						if (isset($hunch['database'])) {
							$dbInfo['database'] = $hunch['database'];
						}
					}
				}
				$this->db = new MysqliDb($sqlServer, $dbInfo['username'], $dbInfo['password'], $dbInfo['database']);
				$this->db->setPrefix($dbInfo['prefix']);
				$this->db->connect();
			} catch(\Exception $e) {
				throw new Exception($e->getMessage());
			}
		}
		return $this->db;
	}

	protected function hunchDbInfo(): ?array {
		$log = Log::getInstance();
		$daMyConf = new File\SFTP("/usr/local/directadmin/conf/my.cnf");
		$daMyConf->setDriver($this->home->getDriver());
		if ($daMyConf->exists()) {
			if (preg_match("/user=(.+)\\npassword=(.+)/i", $daMyConf->read(), $matches)) {
				return array(
					'host' => $this->home->getDriver()->getSSH()->getHost(),
					'username' => $matches[1],
					'password' => $matches[2],
				);
			}
		}
	}
	public function getOption(string $name) {
		try {
			return $this->requireDB()->where("option_name", $name)->getValue("options", "option_value");
		} catch(\Exception $e) {
			throw new Exception($e->getMessage());
		}
	}
	public function setOption(string $name, $value) {
		return $this->requireDB()->replace("options", array(
			"option_name" => $name,
			'option_value' => $value,
			'autoload' => 'yes'
		));
	}
	public function getWPVersion() {
		$version = $this->home->file("wp-includes/version.php");
		if (preg_match("/\\\$wp_version\s*=\s*[\'|\"]([^\'|^\"]+)[\'|\"];/", $version->read(), $matches)) {
			return $matches[1];
		}
	}
	public function getLocale(): string {
		if (!$this->locale) {	
			$this->getDatabaseInfo();
			if (!$this->locale) {
				$this->locale = $this->getOption('WPLANG');
			}
			if (!$this->locale) {
				$this->locale = 'en_US';
			}
		}
		return $this->locale;
	}

	/**
	 * Get the value of config
	 *
	 * @return  file
	 */ 
	public function getConfig()
	{
		return $this->config;
	}

	/**
	 * Set the value of config
	 *
	 * @param  File  $config
	 *
	 * @return  self
	 */ 
	public function setConfig(File $config)
	{
		$this->config = $config;

		return $this;
	}

	/**
	 * Specify data which should be serialized to JSON
	 * 
	 * @return string
	 */
	public function jsonSerialize() {
		throw new Exception("TODO");
		return $this->config;
	}
}
