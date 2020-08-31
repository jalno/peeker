<?php
namespace packages\peeker;
use packages\base\{db\MysqliDb,packages, log, IO\directory\local as directory, IO, IO\file\local as file, http\client};

class WordpressScript extends Script {
	public static function downloadVersion(string $version) {
		$repo = new directory(packages::package('peeker')->getFilePath("storage/private/wordpress-versions/{$version}"));
		$src = $repo->directory("wordpress");
		if ($src->exists()) {
			return $src;
		}
		if (!$repo->exists()) {
			$repo->make(true);
		}
		$zipFile = new IO\file\tmp();
		$http = new client();
		$http->get("https://wordpress.org/wordpress-{$version}.zip", array(
			'save_as' => $zipFile
		));
		$zip = new \ZipArchive();
		if ($zip->open($zipFile->getPath()) === false) {
			throw new \Exception("cannot open zip file");
		}
		$zip->extractTo($repo->getPath());
		$zip->close();
		return $src;
	}
	public static function downloadTheme(string $name): ?Directory {
		$repo = Packages::package("peeker")->getHome()->directory("storage/private/themes");
		if (!$repo->exists()) {
			$repo->make(true);
		}
		$src = $repo->directory($name);
		if ($src->exists()) {
			return !$src->isEmpty() ? $src : null;
		} else {
			$src->make();
		}
		$http = new Client(array(
			"base_uri" => "http://peeker.jeyserver.com/",
		));
		$zipFile = new IO\file\Tmp();
		$http->get("themes/{$name}.zip", array(
			"save_as" => $zipFile
		));
		$zip = new \ZipArchive();
		if ($zip->open($zipFile->getPath()) === false) {
			throw new \Exception("Cannot open zip file");
		}
		$zip->extractTo($src->getPath());
		$zip->close();

		return $src;
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
	public function __construct(file $config) {
		parent::__construct($config->getDirectory());
		$this->config = $config;
	}
	/**
	 * @return array
	 */
	public function getDatabaseInfo() {
		if (!$this->dbInfo) {
			$log = log::getInstance();
			$log->debug("read wp-config.php");
			$content = $this->config->read();
			$dbInfo = [];
			foreach(['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST'] as $const) {
				$log->debug("looking for", $const);
				if (preg_match("/define\([\'|\"]{$const}[\'|\"],\s*[\'|\"]([^\"|^\']+)[\'|\"]\);/", $content, $matches)) {
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
		}
		return $dbInfo;
	}
	/**
	 * @return MysqliDb
	 */
	public function requireDB() {
		if (!$this->db) {
			$dbInfo = $this->getDatabaseInfo();
			try {

				$this->db = new MysqliDb('localhost', $dbInfo['username'], $dbInfo['password'], $dbInfo['database']);
				$this->db->setPrefix($dbInfo['prefix']);
				$this->db->connect();
			} catch(\Exception $e) {
				throw new Exception($e->getMessage());
			}
		}
		return $this->db;
	}
	public function getOption(string $name) {
		try {
			return $this->requireDB()->where("option_name", $name)->getValue("options", "option_value");
		} catch(\Exception $e) {
			throw new Exception($e->getMessage());
		}
	}
	public function getWPVersion() {
		$version = $this->home->file("wp-includes/version.php");
		if (preg_match("/\\\$wp_version\s*=\s*[\'|\"]([^\'|^\"]+)[\'|\"];/", $version->read(), $matches)) {
			return $matches[1];
		}
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
	 * @param  file  $config
	 *
	 * @return  self
	 */ 
	public function setConfig(file $config)
	{
		$this->config = $config;

		return $this;
	}
}
