<?php
namespace packages\peeker\processes;
use packages\base\{log, IO\directory\local as directory, IO\file\local as file, process, packages, json};
use packages\peeker\{WordpressScript, Script};
class WhichWordpress extends process {
	const CLEAN = 0;
	const INFACTED = 1;

	const REPLACE = 0;
	const REMOVE = 1;
	const HANDCHECK = 2;
	const EXECUTABLE = 3;
	const REPAIR = 4;

	protected $cleanMd5;
	protected $infactedMd5;
	protected $actions = [];
	public function start() {
		ini_set('memory_limit','-1');
		log::setLevel("info");
		$log = log::getInstance();
		$log->info("reload actions");
		$this->reloadAction();
		$log->reply("Success");
		$log->info("looking in /home for users");
		$home = new directory("/home");
		$users = $home->directories(false);
		$log->reply(count($users), "found");
		foreach ($users as $user) {
			$log->info($user->basename);
			$this->handleUser($user->basename);
			$this->rewriteAction();
		}
		$this->doActions();
		foreach($users as $user) {
			$log->info("reset permissions:");
			shell_exec("find {$user->getPath()}/public_html -type f -exec chmod 0644 {} \;");
			shell_exec("find {$user->getPath()}/public_html -type d -exec chmod 0755 {} \;");
			$log->reply("Success");
		}
	}
	protected function handleUser(string $user) {
		$log = log::getInstance();
		$log->info("looking in \"domains\" directory");
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
			$log->info($domain->basename);
			try {
				$this->handleDomain($user, $domain->basename);
			} catch (\Exception $e) {
				$log->reply()->error("failed");
				$log->error($e->getMessage());
			}
		}
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
			$log->info("looking in ".$dir->basename." for wordpress");
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
		foreach($files as $file) {
			$ext = $file->getExtension();
			if ($ext == "ico" and substr($file->basename, 0, 1) == ".") {
				$reletivePath = substr($file->getPath(), $homeLen+1);
				$log->debug("check", $reletivePath);
				$log->reply("Infacted");
				$log->append(", Action: Remove");
				$this->addAction(array(
					'file' => $file->getRealPath(),
					'action' => self::REMOVE,
				));
			}
			$reletivePath = substr($file->getPath(), $homeLen+1);
			if ($ext == "suspected") {
				$log->debug("check", $reletivePath);
				$log->reply("suspected extension, Action: Remove");
				$this->addAction(array(
					'file' => $file->getRealPath(),
					'action' => self::REMOVE,
				));
				continue;
			}
			$isExecutable = is_executable($file->getPath());
			if ($ext != "php" and $ext != "js") {
				if ($isExecutable) {
					$log->debug($reletivePath, "is executable");
					$this->addAction(array(
						'file' => $file->getRealPath(),
						'action' => self::EXECUTABLE,
					));
				}
				continue;
			}
			$log->debug("check", $reletivePath);
			$result = $this->isCleanFile($home, $orgWP, $reletivePath);
			if ($result['status'] == self::CLEAN) {
				if ($isExecutable) {
					$log->debug($reletivePath, "is executable");
					$this->addAction(array(
						'file' => $file->getRealPath(),
						'action' => self::EXECUTABLE,
					));
				}
				$log->reply("Clean");
			} else if ($result['status'] == self::INFACTED) {
				$log->reply("Infacted");
				if ($reletivePath == "wp-config.php" and $result['action'] == self::REMOVE) {
					$result['action'] = self::HANDCHECK;
				}
				if ($result['action'] == self::REPLACE) {
					$log->append(", Action: Replace with {$result['file']->getPath()}");
					$this->addAction(array(
						'file' => $file->getRealPath(),
						'action' => self::REPLACE,
						'original' => $result['file']->getRealPath()
					));
				} else if ($result['action'] == self::REMOVE) {
					$log->append(", Action: Remove");
					$this->addAction(array(
						'file' => $file->getRealPath(),
						'action' => self::REMOVE,
					));
				} else if ($result['action'] == self::HANDCHECK) {
					$log->append(", Action: Hand-Check");
					$this->addAction(array(
						'file' => $file->getRealPath(),
						'action' => self::HANDCHECK,
					));
				} else if ($result['action'] == self::REPAIR) {
					$log->append(", Action: Repair, Problem: {$result['problem']}");
					$this->addAction(array(
						'file' => $file->getRealPath(),
						'action' => self::REPAIR,
						'problem' => $result['problem']
					));
				}
			}
		}
	}
	public function reloadCleanMd5() {
		$file = packages::package('peeker')->getFilePath('storage/private/cleanMd5.txt');
		if (!is_file($file)) {
			touch($file);
		}
		$this->cleanMd5 = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		$this->cleanMd5 = array_unique($this->cleanMd5);
	}
	public function cleanMd5(string $md5) {
		if (!$this->cleanMd5) {
			$this->reloadCleanMd5();
		}
		if (!in_array($md5, $this->cleanMd5)) {
			$this->cleanMd5[] = $md5;
			$file = new file(packages::package('peeker')->getFilePath('storage/private/cleanMd5.txt'));
			$file->append($md5."\n");
		}
	}
	public function isCleanMd5(string $md5) {
		if (!$this->cleanMd5) {
			$this->reloadCleanMd5();
		}
		if (in_array($md5, $this->cleanMd5)){
			return true;
		}
		return false;
	}
	public function reloadInfactedMd5() {
		$file = packages::package('peeker')->getFilePath('storage/private/infactedMd5.txt');
		if (!is_file($file)) {
			touch($file);
		}
		$this->infactedMd5 = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		$this->infactedMd5 = array_unique($this->infactedMd5);
	}
	public function infactedMd5(string $md5) {
		if (!$this->infactedMd5) {
			$this->reloadInfactedMd5();
		}
		if (!in_array($md5, $this->infactedMd5)) {
			$this->infactedMd5[] = $md5;
			$file = new file(packages::package('peeker')->getFilePath('storage/private/infactedMd5.txt'));
			$file->append($md5."\n");
		}
	}
	public function isInfactedMd5(string $md5) {
		if (!$this->infactedMd5) {
			$this->reloadInfactedMd5();
		}
		if (in_array($md5, $this->infactedMd5)){
			return true;
		}
		return false;
	}
	public function isCleanFile(directory $home, directory $src, string $file) {
		$homeFile = $home->file($file);
		$homeMd5 = $homeFile->md5();
		$ext = $homeFile->getExtension();
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
		} elseif ($ext == "php") {
			if (
				!in_array($file, ["wp-config.php", "wp-config-sample.php", "wp-content/advanced-cache.php"]) and
				substr($file, 0, 18) != "wp-content/themes/" and 
				substr($file, 0, 19) != "wp-content/plugins/" 
			) {
				$log = log::getInstance();
				$log->debug($file, "does not in wordpress source");
				$prefixs = array('wp-admin/', 'wp-includes/', 'wp-content/languages/', 'wp-content/uploads/', 'wp-content/cache/');
				foreach($prefixs as $prefix) {
					if (substr($file, 0, strlen($prefix)) == $prefix) {
						return array(
							'status' => self::INFACTED,
							'action' => self::REMOVE,
						);
					}
				}
			}
		}

		if ($homeFile->basename == "adminer.php" or $homeFile->basename == "wp.php" or $homeFile->basename == "wp-build-report.php") {
			return array(
				'status' => self::INFACTED,
				'action' => self::REMOVE,
			);
		}
		$content = $homeFile->read();

		if ($ext == "js") {
			if (preg_match("/^var .{1000,},_0x[a-z0-9]+\\)\\}\\(\\)\\);/", $content)) {
				return array(
					'status' => self::INFACTED,
					'action' => self::REPAIR,
					'problem' => 'injectedJS'
				);
			}
			return array(
				'status' => self::CLEAN,
			);
		} elseif ($ext == "php") {
			if (preg_match("/^\<\?php .{200,}/", $content) or preg_match("/var .{1000,},_0x[a-z0-9]+\\)\\}\\(\\)\\);/", $content)) {
				return array(
					'status' => self::INFACTED,
					'action' => self::HANDCHECK,
				);
			}
		}
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
			'eval(gzuncompress(',
			'fopen("part$i"',
			'if (count($ret)>2000) continue;'
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

		if ($this->isInfactedMd5($homeMd5)) {
			return array(
				'status' => self::INFACTED,
				'action' => self::REMOVE,
			);
		} else if ($this->isCleanMd5($homeMd5)) {
			return array(
				'status' => self::CLEAN,
			);
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
					echo $this->highlight($words, $content)."\n";
				} else if ($response == "Y") {
					$this->cleanMd5($homeMd5);
					return array(
						'status' => self::CLEAN,
					);
				} else if ($response == "N") {
					$this->infactedMd5($homeMd5);
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
		if (!is_array($needle)) {
			$needle = [$needle];
		}
		foreach($needle as $item) {
			$ind = stripos($haystack, $item);
			$len = strlen($item);
			if($ind !== false){
				$haystack = substr($haystack, 0, $ind) . "\033[0;31m" . substr($haystack, $ind, $len) . "\033[0m" . $this->highlight($item, substr($haystack, $ind + $len));
			}
		}
		return $haystack;
	}
	public function doActions() {
		$log = log::getInstance();
		$log->info(count($this->actions), "actions");
		foreach($this->actions as $item) {
			$item['file'] = new file($item['file']);
			if ($item['action'] == self::REPLACE) {
				$item['original'] = new file($item['original']);
				$log->info("Copy {$item['original']->getPath()} to {$item['file']->getPath()}");
				$item['original']->copyTo($item['file']);
				$log->reply("Success");
			} elseif ($item['action'] == self::REMOVE) {
				$log->info("Remove {$item['file']->getPath()}");
				$item['file']->delete();
				$log->reply("Success");
			} elseif ($item['action'] == self::HANDCHECK or $item['action'] == self::EXECUTABLE) {
				if ($item['action'] == self::EXECUTABLE) {
					$log->info("This file is executable {$item['file']->getPath()}:");
					chmod($item['file']->getPath(), 0644);
				} else {
					$log->info("Hand-check {$item['file']->getPath()}");
				}
				while(true) {
					echo("Please check {$item['file']->getRealPath()} and type OK:");
					$response = strtolower(trim(fgets(STDIN)));
					if ($response == "ok") {
						$log->reply("Success");
						break;
					}
				}
			} elseif ($item['action'] == self::REPAIR) {
				if ($item['problem'] == 'injectedJS') {
					$log->info("Repair injected JS {$item['file']->getPath()}");
					$content = $item['file']->read();
					$content = preg_replace("/^var .{1000,},_0x[a-z0-9]+\\)\\}\\(\\)\\);(.*)/", "$1", $content);
					//$item['file']->basename .= ".new";
					$item['file']->write($content);

					/*while(true) {
						echo("Please check {$item['file']->getRealPath()} and type OK:");
						$response = strtolower(trim(fgets(STDIN)));
						if ($response == "ok") {
							$log->reply("Success");
							break;
						}
					}
					$item['file']->rename(substr($item['file']->basename, 0, strlen($item['file']->basename) - 4));
					*/
				}
			}
		}
		$this->actions = [];
	}
	private function reloadAction() {
		$file = new file(packages::package('peeker')->getFilePath('storage/private/actions.json'));
		$this->actions = $file->exists() ? json\decode($file->read()) : [];
		if (!is_array($this->actions)) {
			$this->actions = [];
		}
	}
	private function addAction($action) {
		$this->actions[] = $action;
	}
	private function rewriteAction() {
		$file = new file(packages::package('peeker')->getFilePath('storage/private/actions.json'));
		$file->write(json\encode($this->actions, json\PRETTY));
	}
}
