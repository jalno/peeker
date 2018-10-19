<?php
namespace packages\peeker\processes;
use packages\base\{log, IO\directory\local as directory, IO\file\local as file, process, packages};
use packages\peeker\{WordpressScript, Script};
class WhichWordpress extends process {
	const CLEAN = 0;
	const INFACTED = 1;
	const REPLACE = 0;
	const REMOVE = 1;
	const HANDCHECK = 2;
	protected $cleanMd5;
	protected $actions = [];
	public function start() {
		log::setLevel("debug");
		$log = log::getInstance();
		$log->debug("looking in /home for users");
		$home = new directory("/home");
		$users = $home->directories(false);
		$log->reply(count($users), "found");
		foreach ($users as $user) {
			$log->debug($user->basename);
			$this->handleUser($user->basename);
			break;
		}
		$this->doActions();
	}
	protected function handleUser(string $user) {
		$log = log::getInstance();
		$log->debug("looking in \"domains\" directory");
		$userDir = new directory("/home/".$user);
		$domainsDirectory = $userDir->directory("domains");
		if (!$domainsDirectory->exists()) {
			$log->reply()->fatal("directory is not exists");
			return;
		}
		$domains = $domainsDirectory->directories(false);
		if (!$domains) {
			$log->reply()->fatal("no domain exists");
			return;
		}
		$log->reply(count($domains), "found");
		foreach ($domains as $domain) {
			$log->debug($domain->basename);
			try {
				$this->handleDomain($user, $domain->basename);
			} catch (\Exception $e) {
				$log->reply()->error("failed");
				$log->error($e->getMessage());
			}
		}
		$log->info("reset permissions:");
		shell_exec("find -type f -exec chmod 0644 {} \;");
		shell_exec("find -type d -exec chmod 0755 {} \;");
		$log->reply("Success");
	}
	protected function globalIgnoreDirs() {
		return ["wp-admin", "wp-includes", "wp-content"];
	}
	protected function findWPConfigs(directory $root) {
		$configs = [];
		$ignoreDirs = $this->globalIgnoreDirs();
		$directories = $root->directories(false);
		foreach ($directories as $directory) {
			if (in_array($directory->basename, $ignoreDirs)) {
				continue;
			}
			$wpConfig = $this->findWPConfigs($directory);
			if ($wpConfig) {
				foreach ($wpConfig as $config) {
					$configs[] = $config;
				}
			}
		}

		$files = $root->files(false);
		foreach ($files as $file) {
			if ($file->basename == "wp-config.php") {
				$configs[] = $file;
				break;
			}
		}
		return $configs;
	}
	protected function handleDomain(string $user, string $domain) {
		$log = log::getInstance();
		$domainDir = new directory("/home/".$user."/domains/".$domain);
		$dirs = [$domainDir->directory("public_html")];
		foreach ($dirs as $dir) {
			if (!$dir->exists()) {
				continue;
			}
			$log->debug("looking in ".$dir->basename." for wordpress");
			$configs = $this->findWPConfigs($dir);
			$log->reply(count($configs), "found");
			foreach ($configs as $config) {
				$wp = new WordpressScript($config);
				$log->info($wp->getHome()->getPath().": ");
				$this->handleWp($wp);
			}
		}
	}
	protected function handleWp(WordpressScript $wp) {
		$log = log::getInstance();
		$log->info("get version");
		$version = $wp->getWPVersion();
		if (!$version) {
			$log->reply()->fatal("not found");
			throw new \Exception("cannot find wordpress version");
		}
		$log->reply($version);
		$log->info("download orginal version");
		$orgWP = WordpressScript::downloadVersion($version);
		$log->reply("saved in", $orgWP->getPath());
		$home = $wp->getHome();
		$files = $home->files(true);
		$homeLen = strlen($home->getPath());
		$actions = [];
		foreach($files as $file) {
			$ext = $file->getExtension();
			if ($ext == "ico" and substr($file->basename, 0, 1) == ".") {
				$reletivePath = substr($file->getPath(), $homeLen+1);
				$log->debug("check", $reletivePath);
				$log->reply("Infacted");
				$log->append(", Action: Remove");
				$actions[] = array(
					'file' => $file,
					'action' => self::REMOVE,
				);
			}
			if ($ext != "php") {
				continue;
			}
			$reletivePath = substr($file->getPath(), $homeLen+1);
			$log->debug("check", $reletivePath);
			$result = $this->isCleanFile($home, $orgWP, $reletivePath);
			if ($result['status'] == self::CLEAN) {
				$log->reply("Clean");
			} else if ($result['status'] == self::INFACTED) {
				$log->reply("Infacted");
				if ($result['action'] == self::REPLACE) {
					$log->append(", Action: Replace with {$result['file']->getPath()}");
					$actions[] = array(
						'file' => $file,
						'action' => self::REPLACE,
						'original' => $result['file']
					);
				} else if ($result['action'] == self::REMOVE) {
					$log->append(", Action: Remove");
					$actions[] = array(
						'file' => $file,
						'action' => self::REMOVE,
					);
				} else if ($result['action'] == self::HANDCHECK) {
					$log->append(", Action: Hand-Check");
					$actions[] = array(
						'file' => $file,
						'action' => self::HANDCHECK,
					);
				}
			}
		}
		$this->actions = array_merge($this->actions, $actions);
	}
	public function reloadMd5() {
		$file = packages::package('peeker')->getFilePath('storage/private/cleanMd5.txt');
		if (!is_file($file)) {
			touch($file);
		}
		$this->cleanMd5 = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	}
	public function cleanMd5(string $md5) {
		if (!$this->cleanMd5) {
			$this->reloadMd5();
		}
		$this->cleanMd5[] = $md5;
		$file = new file(packages::package('peeker')->getFilePath('storage/private/cleanMd5.txt'));
		$file->append($md5."\n");
	}
	public function isCleanMd5(string $md5) {
		if (!$this->cleanMd5) {
			$this->reloadMd5();
		}
		if (in_array($md5, $this->cleanMd5)){
			return true;
		}
		return false;
	}
	public function isCleanFile(directory $home, directory $src, string $file) {
		$homeFile = $home->file($file);
		$homeMd5 = $homeFile->md5();
		if ($this->isCleanMd5($homeMd5)) {
			return array(
				'status' => self::CLEAN,
			);
		}
		$srcFile = $src->file($file);
		if ($srcFile->exists()) {
			if ($srcFile->md5() == $homeMd5) {
				$this->cleanMd5($homeMd5);
				return array(
					'status' => self::CLEAN,
				);
			} else {
				if ($file != "wp-includes/version.php") {
					return array(
						'status' => self::INFACTED,
						'action' => self::REPLACE,
						'file' => $srcFile,
					);
				}
			}
		} else {
			if (
				!in_array($file, ["wp-config.php", "wp-config-sample.php", "wp-content/advanced-cache.php"]) and
				substr($file, 0, 18) != "wp-content/themes/" and 
				substr($file, 0, 19) != "wp-content/plugins/" 
			) {
				$log = log::getInstance();
				$log->debug($file, "does not in wordpress source");
				if (substr($file, 0, 9) == "wp-admin/" or substr($file, 0, 12) == "wp-includes/") {
					return array(
						'status' => self::INFACTED,
						'action' => self::REMOVE,
					);
				}
			}
		}
		if ($homeFile->basename == "adminer.php" or $homeFile->basename == "wp.php") {
			return array(
				'status' => self::INFACTED,
				'action' => self::REMOVE,
			);
		}
		if (strpos($file, "wp-content/uploads") !== false) {
			return array(
				'status' => self::INFACTED,
				'action' => self::REMOVE,
			);
		}
		if (strpos($file, "wp-content/cache") !== false) {
			return array(
				'status' => self::INFACTED,
				'action' => self::REMOVE,
			);
		}
		$content = $homeFile->read();
		if (preg_match("/\/\*[a-z0-9]+\*\/\s+\@include\s+(.*);\s+\/\*[a-z0-9]+\*/im", $content)) {
			return array(
				'status' => self::INFACTED,
				'action' => self::REMOVE,
			);
		}
		$words = array(
			'is_joomla',
			'testtrue',
			'add_backlink_to_post',
			'str_split(rawurldecode(str_rot13',
			'include \'check_is_bot.php\'',
			'eval(gzuncompress('
		);
		foreach($words as $word) {
			if (stripos($content, $word) !== false) {
				return array(
					'status' => self::INFACTED,
					'action' => self::REMOVE,
				);
			}
		}
		$words = array(
			'mapilo.net',
			'theme_temp_setup',
			'div_code_name',
			'start_wp_theme_tmp',
		);
		foreach($words as $word) {
			if (stripos($content, $word) !== false) {
				return array(
					'status' => self::INFACTED,
					'action' => self::HANDCHECK,
				);
			}
		}
		$words = array(
			'move_uploaded_file',
			'eval',
		);
		$found = false;
		foreach($words as $word) {
			if (stripos($content, $word) !== false) {
				$found = true;
				break;
			}
		}
		if ($found) {
			while(true) {
				echo($homeFile->getPath()."\nIs this file is clean? [S=Show Content, Y=Clean, N=Infacted]: ");
				$response = trim(fgets(STDIN));
				if ($response == "S") {
					echo $this->highlight("eval", $content)."\n";
				} else if ($response == "Y") {
					$this->cleanMd5($homeMd5);
					return array(
						'status' => self::CLEAN,
					);
				} else if ($response == "N") {
					return array(
						'status' => self::INFACTED,
						'action' => self::REMOVE,
					);
				}
			}
		}
		return array(
			'status' => self::CLEAN,
		);
	}
	public function highlight($needle, $haystack){
		$ind = stripos($haystack, $needle);
		$len = strlen($needle);
		if($ind !== false){
			return substr($haystack, 0, $ind) . "\033[0;31m" . substr($haystack, $ind, $len) . "\033[0m" . $this->highlight($needle, substr($haystack, $ind + $len));
		}
		return $haystack;
	}
	public function doActions() {
		$log = log::getInstance();
		$log->info(count($this->actions), "actions");
		foreach($this->actions as $item) {
			if ($item['action'] == self::REPLACE) {
				$log->info("Copy {$item['original']->getPath()} to {$item['file']->getPath()}");
				$item['original']->copyTo($item['file']);
				$log->reply("Success");
			} else if ($item['action'] == self::REMOVE) {
				$log->info("Remove {$item['file']->getPath()}");
				$item['file']->delete();
				$log->reply("Success");
			} else if ($item['action'] == self::HANDCHECK) {
				while(true) {
					echo("Please check {$item['file']->getRealPath()} and type OK:");
					$response = strtolower(trim(fgets(STDIN)));
					if ($response == "ok") {
						$log->reply("Success");
						break;
					}
				}
			}
		}
	}
}
