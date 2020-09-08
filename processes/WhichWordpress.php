<?php
namespace packages\peeker\processes;
use packages\base\{log, IO\directory\local as directory, IO\file\local as file, process, packages, json, View\Error};
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

	protected $wp = [];
	protected $themes = [];
	protected $plugins = [];

	public function start(array $data) {
		Log::setLevel("debug");
		ini_set('memory_limit','-1');
		error_reporting(E_ALL);
		ini_set("display_errors", true);

		$log = Log::getInstance();
		if (isset($data["reloadac"]) and $data["reloadac"]) {
			$log->info("reload actions");
			$this->reloadAction();
			$log->reply("Success");
		} else {
			$log->info("No need to reload actions");
		}
		$users = array();
		$home = new directory("/home");
		if (isset($data["user"]) and $data["user"]) {
			$log->info("looking for user", $data["user"]);
			$user = $home->directory($data["user"]);
			if (!$user->exists()) {
				throw new Error("Unable to find any user by username " . $data["user"] . " in /home");
			}
			$users[] = $user;
			$log->reply("Found");
		} else {
			$log->info("looking in /home for users");
			$users = $home->directories(false);
			$log->reply(count($users), "found");
		}
		$doneUsers = [];
		foreach ($users as $user) {
			$log->info($user->basename);
			try {
				$this->handleUser($user->basename);
				$this->rewriteAction();
				$doneUsers[] = $user;
			} catch (\Exception $e) {}
		}
		$this->doActions();
		return;
		foreach($doneUsers as $user) {
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
			throw new Error("directory is not exists");
		}
		$domains = $domainsDirectory->directories(false);
		if (!$domains) {
			$log->reply()->fatal("no domain exists");
			throw new Error("no domain exists");
		}
		$log->reply(count($domains), "found");
		foreach ($domains as $domain) {
			$log->info($domain->basename);
			try {
				$this->handleDomain($user, $domain->basename);
			} catch (\Exception $e) {
				$log->reply()->error("failed");
				$log->error($e->__toString());
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
		$log = Log::getInstance();
		$log->info("get wp version");
		$version = $wp->getWPVersion();
		if (!$version) {
			$log->reply()->fatal("not found");
			throw new \Exception("cannot find wordpress version");
		}
		$log->reply($version);
		$log->info("download original version");
		$orgWP = WordpressScript::downloadVersion($version);
		$this->wp = array(
			'version' => $version,
			'org' => $orgWP,
		);
		$log->reply("saved in", $orgWP->getPath());
		$home = $wp->getHome();

		$log->info("get plugins and versions");
		$pluginsDir = $home->directory("wp-content/plugins");
		foreach ($pluginsDir->directories(false) as $pluginDir) {
			$log->info("plugin: {$pluginDir->basename}");
			$pluginInfo = $this->getPluginInfo($pluginDir);
			if (empty($pluginInfo)) {
				$log->reply()->warn("it seems plugin is damaged!");
				$this->plugins[$pluginDir->basename] = array(
					'path' => $pluginDir->basename,
				);
				continue;
			}
			$log->reply("version:", $pluginInfo['version']);
			$log->info("get original plugin");
			$orgPlugin = null;
			try {
				$orgPlugin = WordpressScript::downloadPlugin($pluginDir->basename, ($pluginInfo['version'] ?? null));
			} catch (\Exception $e) {}
			if ($orgPlugin) {
				$log->reply("done");
			} else {
				$log->reply()->warn("not found!");
			}
			$this->plugins[$pluginDir->basename] = array(
				'name' => $pluginInfo['name'],
				'path' => $pluginInfo['path'] ?? '',
				'version' => $pluginInfo['version'] ?? '',
				'org' => $orgPlugin,
			);
		}
		$log->info("get themes");
		$themesDir = $home->directory("wp-content/themes");
		foreach ($themesDir->directories(false) as $themeNameDir) {
			$log->info("theme: {$themeNameDir->basename}");
			$log->info("download original versions of theme");
			$theme = null;
			try {
				$theme = WordpressScript::downloadTheme($themeNameDir->basename);
			} catch (\Exception $e) {}
			if ($theme) {
				$log->reply("done");
			} else {
				$log->reply()->warn("not found!");
			}
			$this->themes[$themeNameDir->basename] = array(
				'name' => $themeNameDir->basename,
				'path' => $themeNameDir->basename,
				'org' => $theme,
			);
		}

		$log->info("try check each file");
		$files = $home->files(true);
		foreach($files as $file) {
			$ext = $file->getExtension();
			$reletivePath = substr($file->getPath(), strlen($home->getPath()) + 1);
			if ($ext == "ico" and substr($file->basename, 0, 1) == ".") {
				$log->debug("check", $reletivePath);
				$log->reply("Infacted");
				$log->append(", Action: Remove");
				$this->addAction(array(
					'file' => $file->getRealPath(),
					'action' => self::REMOVE,
				));
				continue;
			} elseif ($ext == "suspected") {
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
			$result = $this->isCleanFile($reletivePath, $home, $orgWP);
			if ($result['status'] == self::CLEAN) {
				if ($isExecutable) {
					$log->debug($reletivePath, "is executable");
					// $this->addAction(array(
					// 	'file' => $file->getRealPath(),
					// 	'action' => self::EXECUTABLE,
					// ));
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
						'original' => $result['file']->getRealPath(),
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
	protected function getPluginInfo(Directory $pluginDir): array {
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
	public function isCleanFile(string $file, directory $home, directory $src) {
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
			if (substr($file, 0, 18) == "wp-content/themes/") {
				$startFilePathPos = strpos($file, "/", 18);
				$name = substr($file, 18, $startFilePathPos - 18);
				if (!in_array($name, ["twentytwenty", "twentynineteen"])) {
					$theme = $this->themes[$name]['org'];
					if ($theme) {
						$versions = array();
						if (!empty($theme->files(false))) {
							$versions = [$theme];
						} else {
							$versions = $theme->directories(false);
						}
						$isClean = false;
						$fileRelativePath = substr($file, $startFilePathPos + 1);
						foreach ($versions as $version) {
							$srcFile = $version->file($fileRelativePath);
							if ($srcFile->exists() and $srcFile->md5() == $homeMd5) {
								$this->cleanMd5($homeMd5);
								$isClean = true;
								break;
							}
						}
						$res = array(
							"status" => $isClean ? self::CLEAN : self::INFACTED,
						);
						if (!$isClean) {
							$res["action"] = self::HANDCHECK;
						}
						return $res;
					}
				}
			} elseif (substr($file, 0, 19) == "wp-content/plugins/") {
				$startFilePathPos = strpos($file, "/", 19);
				$pluginName = substr($file, 19, $startFilePathPos - 19);
				$plugin = $this->plugins[$pluginName]['org'];
				if ($plugin) {
					$fileRelativePath = substr($file, 19);
					$homeFile = $home->directory("wp-content/plugins/")->file($fileRelativePath);
					$homeMd5 = $homeFile->md5();
					$srcFile = $plugin->file($fileRelativePath);
					$result = array(
						"status" => self::INFACTED,
					);
					if ($srcFile->exists()) {
						if ($srcFile->md5() == $homeMd5) {
							$this->cleanMd5($homeMd5);
							$result["status"] = self::CLEAN;
						} else {
							$result["action"] = self::REPLACE;
							$result["file"] = $srcFile;
						}
					} else {
						$result["status"] = self::CLEAN;
						$result["action"] = self::REMOVE;
					}
					if ($srcFile->exists() and $srcFile->md5() == $homeMd5) {
						$isClean = true;
					}
					return $result;
				}
			} elseif (!in_array($file, ["wp-config.php", "wp-config-sample.php", "wp-content/advanced-cache.php"])) {
				$log = Log::getInstance();
				$log->debug($file, "does not in wordpress source, themes and plugins");
				$prefixs = array('wp-admin/', 'wp-includes/', 'wp-content/languages/', 'wp-content/uploads/', 'wp-content/cache/');
				foreach ($prefixs as $prefix) {
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
		if (
			preg_match("/\/\*[a-z0-9]+\*\/\s+\@include\s+(.*);\s+\/\*[a-z0-9]+\*/im", $content) or
			preg_match("/108.+111.+119.+101.+114.+98.+101.+102.+111.+114.+119.+97.+114.+100.+101.+110/", $content) or
			preg_match('/<script.+ src=[\"\'].+lowerbeforwarden.+[\"\'].*><\/script>/i', $content)
		) {
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
			'if (count($ret)>2000) continue;',
			'Class_UC_key'
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
		$log->info(count($this->actions), " actions");
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
		$file = Packages::package('peeker')->getFile('storage/private/actions.json');
		$this->actions = $file->exists() ? json\decode($file->read()) : [];
		if (!is_array($this->actions)) {
			$this->actions = [];
		}
	}
	private function addAction($action) {
		$this->actions[] = $action;
	}
	private function rewriteAction() {
		$file = Packages::package('peeker')->getFile('storage/private/actions.json');
		$directory = $file->getDirectory();
		if (!$directory->exists()) {
			$directory->make(true);
		}
		$file->write(json\encode($this->actions, json\PRETTY));
	}
}
