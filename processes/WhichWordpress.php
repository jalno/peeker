<?php
namespace packages\peeker\processes;
use packages\base\{log, IO\directory\local as directory, IO\file\local as file, process, packages, json, View\Error};
use packages\peeker\{WordpressScript, Script, PluginException};
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
	protected $failedPluginDownloads = [];

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
		foreach($doneUsers as $user) {
			$log->info("reset permissions:");
			shell_exec("find {$user->getPath()}/public_html/ -type f -exec chmod 0644 {} \;");
			shell_exec("find {$user->getPath()}/public_html/ -type d -exec chmod 0755 {} \;");
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
		$this->wp = array(
			'version' => $version,
			'org' => WordpressScript::downloadVersion($version),
		);
		$log->reply("saved in", $this->wp['org']->getPath());
		$home = $wp->getHome();

		$this->preparePlugins($home);
		$this->prepareThemes($home);
		

		$hasInfacted = false;
		$log->info("try check each file");
		$files = $home->files(true);
		foreach($files as $file) {
			$ext = $file->getExtension();
			$reletivePath = $this->getRelativePath($home, $file);
			$isExecutable = is_executable($file->getPath());
			if ($ext != "php" and $ext != "js") {
				if ($isExecutable) {
					$log->debug($reletivePath, "is executable");
					/*$this->addAction(array(
						'file' => $file->getRealPath(),
						'action' => self::EXECUTABLE,
					));*/
				}
			}
			$log->debug("check", $reletivePath);
			$result = $this->isCleanFile($reletivePath, $home);
			if ($result['status'] == self::CLEAN) {
				$log->reply("Clean");
			} elseif ($result['status'] == self::INFACTED) {
				$log->reply("Infacted");
				if (isset($result['reason'])) {
					$log->append(", Reason: {$result['reason']}");
				}
				if (!isset($result['file'])) {
					$result['file'] = $file;
				}
				if ($reletivePath == "wp-config.php" and $result['action'] == self::REMOVE) {
					$result['action'] = self::HANDCHECK;
				}
				foreach ($result as $key => $value) {
					if ($value instanceof File or $value instanceof Directory) {
						$result[$key] = $value->getPath();
					}
				}
				if ($result['action'] == self::REPLACE) {
					$log->append(", Action: Replace with {$result['original']}");
				} else if ($result['action'] == self::REMOVE) {
					$log->append(", Action: Remove");
				} else if ($result['action'] == self::HANDCHECK) {
					$log->append(", Action: Hand-Check");
					$isCleanMd5 = $this->isCleanMd5($file->md5());
					if ($isCleanMd5) {
						$log->append(", Md5 is clean, Ignore");
						continue;
					}
				} else if ($result['action'] == self::REPAIR) {
					$log->append(", Action: Repair, Problem: {$result['problem']}");
				}

				$this->addAction($result);
				$hasInfacted = true;
			}
		}
		$sql = $wp->requireDB();
		$posts = array_column($sql->where("post_content", "<script", "contains")->get("posts", null, ['ID']), 'ID');
		if ($posts) {
			$hasInfacted = true;
			foreach ($posts as $post) {
				$this->addAction(array(
					'action' => self::REPAIR,
					'problem' => "fix-script-in-post-content",
					'post' => $post,
					'wp' => $wp,
				));
			}
		}
		foreach ([$wp->getOption("siteurl"), $wp->getOption("home")] as $item) {
			if (strpos($item, "?") !== false || strpos($item, "&") !== false) {
				$hasInfacted = true;
				$this->addAction(array(
					'action' => self::REPAIR,
					'problem' => "fix-siteurl",
					'wp' => $wp,
				));
			}
		}
		if ($hasInfacted) {
			$wpRocket = $home->directory("wp-content/cache/wp-rocket");
			if ($wpRocket->exists()) {
				foreach ($wpRocket->directories(false) as $item) {
					$this->addAction(array(
						'action' => self::REMOVE,
						'directory' => $item->getPath(),
						'reason' => 'clear-wp-rocket-cache',
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
	public function isCleanFile(string $file, directory $home): array {
		$homeFile = $home->file($file);

		$ext = $homeFile->getExtension();
		if ($ext == "ico" and substr($homeFile->basename, 0, 1) == ".") {
			return array(
				'status' => self::INFACTED,
				'file' => $homeFile->getRealPath(),
				'action' => self::REMOVE,
				'reason' => 'infacted-ico-file'
			);
		}
		if ($ext == "suspected") {
			return array(
				'status' => self::INFACTED,
				'file' => $homeFile->getRealPath(),
				'action' => self::REMOVE,
				'reason' => 'suspected-file'
			);
		}
		if ($homeFile->basename == "log.txt" or $homeFile->basename == "log.zip") {
			return array(
				'status' => self::INFACTED,
				'file' => $homeFile->getRealPath(),
				'action' => self::REMOVE,
				'reason' => 'bad-name'
			);
		}

		$result = $this->isCleanWPFile($homeFile, $home);
		if ($result) {
			return $result;
		}
		if ($ext == "php") {
			$result = $this->isCleanPHPFile($homeFile, $home);
			if ($result) {
				return $result;
			}
		} elseif ($ext == "js" or $ext == "json") {
			$result = $this->isCleanJSFile($homeFile, $home);
			if ($result) {
				return $result;
			}
		}
		return array(
			'status' => self::CLEAN,
		);
	}

	public function isCleanWPFile(File $file, Directory $home): ?array {
		$log = Log::getInstance();
		$relativePath = $this->getRelativePath($home, $file);
		$original = $this->wp['org']->file($relativePath);
		if (!$original->exists()) {
			return null;
		}
		if ($original->md5() == $file->md5()) {
			return array(
				'status' => self::CLEAN,
			);
		}
		return array(
			'status' => self::INFACTED,
			'action' => self::REPLACE,
			'original' => $original,
			'reason' => 'replace-wordpress-file'
		);
	}
	public function isCleanPHPFile(File $file, Directory $home): ?array {
		$log = Log::getInstance();
		$badNames = ["adminer.php", "wp.php", "wpconfig.bak.php", "wp-build-report.php", "wp-stream.php"];
		$badNamesPatterns = ["/_index\.php$/"];
		if (in_array($file->basename, $badNames)) {
			return array(
				'status' => self::INFACTED,
				'action' => self::REMOVE,
				'reason' => 'bad-name-php'
			);
		}
		foreach ($badNamesPatterns as $badName) {
			if (preg_match($badName, $file->basename)) {
				return array(
					'status' => self::INFACTED,
					'action' => self::REMOVE,
					'reason' => 'bad-name-php',
					'pattern' => $badName,
				);
			}
		}

		$relativePath = $this->getRelativePath($home, $file);
		if (preg_match("/^wp-content\/themes\/(.+)\//", $relativePath, $matches)) {
			$result = $this->isCleanThemePHPFile($matches[1], $file, $home);
			if ($result) {
				return $result;
			}
		}
		if (preg_match("/^wp-content\/plugins\/(.+)\//", $relativePath, $matches)) {
			$result = $this->isCleanPluginPHPFile($matches[1], $file, $home);
			if ($result) {
				return $result;
			}
		}
		if (!in_array($relativePath, ["wp-config.php", "wp-config-sample.php", "wp-content/advanced-cache.php"])) {
			$prefixs = array('wp-admin/', 'wp-includes/', 'wp-content/languages/', 'wp-content/uploads/', 'wp-content/cache/');
			foreach ($prefixs as $prefix) {
				if (substr($relativePath, 0, strlen($prefix)) == $prefix) {
					return array(
						'status' => self::INFACTED,
						'action' => self::REMOVE,
						'reason' => 'php-file-in-wrong-dir'
					);
				}
			}
		}
		$rules = array(
			array(
				'type' => 'exact',
				'needle' => 'is_joomla',
				'action' => self::REMOVE
			),
			array(
				'type' => 'exact',
				'needle' => 'testtrue',
				'action' => self::REMOVE
			),
			array(
				'type' => 'exact',
				'needle' => 'add_backlink_to_post',
				'action' => self::REMOVE
			),
			array(
				'type' => 'exact',
				'needle' => 'str_split(rawurldecode(str_rot13',
				'action' => self::REMOVE
			),
			array(
				'type' => 'exact',
				'needle' => 'include \'check_is_bot.php\'',
				'action' => self::REMOVE
			),
			array(
				'type' => 'exact',
				'needle' => 'eval(gzuncompress(',
				'action' => self::REMOVE
			),
			array(
				'type' => 'exact',
				'needle' => 'fopen("part$i"',
				'action' => self::REMOVE
			),
			array(
				'type' => 'exact',
				'needle' => 'if (count($ret)>2000) continue;',
				'action' => self::REMOVE
			),
			array(
				'type' => 'exact',
				'needle' => 'Class_UC_key',
				'action' => self::REMOVE
			),
			array(
				'type' => 'exact',
				'needle' => 'LoginWall',
				'action' => self::REMOVE
			),
			array(
				'type' => 'exact',
				'needle' => 'CMSmap',
				'action' => self::REMOVE
			),
			array(
				'type' => 'exact',
				'needle' => 'file_put_contents(\'lte_\',\'<?php \'.$lt)',
				'action' => self::REMOVE
			),
			array(
				'type' => 'exact',
				'needle' => 'go go go',
				'action' => self::REMOVE
			),
			array(
				'type' => 'pattern',
				'needle' => '/<script.*>\s*Element.prototype.appendAfter =.+\)\)\[0\].appendChild\(elem\);}\)\(\);<\/script>/',
				'action' => self::REPAIR,
				'problem' => 'nasty-js-virues-in-php'
			),
			array(
				'type' => 'pattern',
				'needle' => "/^Element.prototype.appendAfter =.+\)\)\[0\].appendChild\(elem\);}\)\(\);/",
				'action' => self::REPAIR,
				'problem' => 'nasty-js-virues',
			),
			array(
				'type' => 'pattern',
				'needle' => '/function .*=substr\(.*\(int\)\(hex2bin\(.*eval\(eval\(eval\(eval/',
				'action' => self::REMOVE
			),
			array(
				'type' => 'pattern',
				'needle' => "/\/\*[a-z0-9]+\*\/\s+\@include\s+(.*);\s+\/\*[a-z0-9]+\*/im",
				'action' => self::REMOVE
			),
			array(
				'type' => 'pattern',
				'needle' => "/108.+111.+119.+101.+114.+98.+101.+102.+111.+114.+119.+97.+114.+100.+101.+110/",
				'action' => self::HANDCHECK
			),
			array(
				'type' => 'pattern',
				'needle' => '/^<script .+<\?php/i',
				'action' => self::REPAIR,
				'problem' => 'injected-lowerbeforwarden-php'
			),
			array(
				'type' => 'pattern',
				'needle' => '/^<script .* src=.*temp\.js.*/i',
				'action' => self::REPAIR,
				'problem' => 'injected-lowerbeforwarden-html'
			),
			array(
				'type' => 'pattern',
				'needle' => '/^<\?php\s{200,}.+eval.+\?><\?php/i',
				'action' => self::REPAIR,
				'problem' => 'injected-php-in-first-line'
			),
			array(
				'type' => 'pattern',
				'needle' => '/^<\?php.+md5.+\?><\?php/i',
				'action' => self::REPAIR,
				'problem' => 'injected-php-in-first-line-md5'
			),
			array(
				'type' => 'pattern',
				'needle' => '/<script.+ src=[\"\'].+lowerbeforwarden.+[\"\'].*><\/script>/i',
				'action' => self::HANDCHECK
			),
			array(
				'type' => 'exact',
				'needle' => '"_F"."IL"."ES"',
				'action' => self::REMOVE
			),

			array(
				'type' => 'exact',
				'needle' => 'mapilo.net',
				'action' => self::HANDCHECK
			),
			array(
				'type' => 'exact',
				'needle' => 'theme_temp_setup',
				'action' => self::HANDCHECK
			),
			array(
				'type' => 'exact',
				'needle' => 'div_code_name',
				'action' => self::HANDCHECK
			),
			array(
				'type' => 'exact',
				'needle' => 'start_wp_theme_tmp',
				'action' => self::HANDCHECK
			),
			array(
				'type' => 'exact',
				'needle' => "function updatefile(\$blacks='')",
				'action' => self::HANDCHECK
			),
			array(
				'type' => 'exact',
				'needle' => "daksldlkdsadas",
				'action' => self::HANDCHECK
			),
			array(
				'type' => 'pattern',
				'needle' => "/^\<\?php .{200,}/",
				'action' => self::HANDCHECK
			),
			array(
				'type' => 'pattern',
				'needle' => "/var .{1000,},_0x[a-z0-9]+\\)\\}\\(\\)\\);/",
				'action' => self::HANDCHECK
			),
			array(
				'type' => 'exact',
				'needle' => 'move_uploaded_file',
				'action' => 'hotcheck'
			),
			array(
				'type' => 'pattern',
				'needle' => '/\Weval\s*[\[\]\~\!\\\'\"\@\#\$%\^\&\*\(\)\-_+=:\{\}\<\>\/\\\\]/',
				'action' => 'hotcheck'
			),
		);
		$result = $this->checkFileContent($rules, $file);
		if ($result) {
			return $result;
		}
		return array(
			'status' => self::CLEAN,
		);
	}

	public function isCleanJSFile(File $file, Directory $home): ?array {
		
		$relativePath = $this->getRelativePath($home, $file);
		if (preg_match("/^wp-content\/themes\/(.+)\//", $relativePath, $matches)) {
			$result = $this->isCleanThemeJSFile($matches[1], $file, $home);
			if ($result) {
				return $result;
			}
		}
		if (preg_match("/^wp-content\/plugins\/(.+)\//", $relativePath, $matches)) {
			$result = $this->isCleanPluginJSFile($matches[1], $file, $home);
			if ($result) {
				return $result;
			}
		}

		$rules = array(
			array(
				'type' => 'pattern',
				'needle' => "/^Element.prototype.appendAfter =.+\)\)\[0\].appendChild\(elem\);}\)\(\);/",
				'action' => self::REPAIR,
				'problem' => 'nasty-js-virues',
			),
			array(
				'type' => 'pattern',
				'needle' => "/108.+111.+119.+101.+114.+98.+101.+102.+111.+114.+119.+97.+114.+100.+101.+110/",
				'action' => self::HANDCHECK
			),
			array(
				'type' => 'pattern',
				'needle' => '/lowerbeforwarden/i',
				'action' => self::HANDCHECK
			),
			array(
				'type' => 'pattern',
				'needle' => "/var .{1000,},_0x[a-z0-9]+\\)\\}\\(\\)\\);/",
				'action' => self::REPAIR,
				'problem' => 'injectedJS',
			),
		);
		$result = $this->checkFileContent($rules, $file);
		if ($result) {
			return $result;
		}
		return array(
			'status' => self::CLEAN,
		);
	}

	public function isCleanThemePHPFile(string $theme, File $file, Directory $home): ?array {
		if (in_array($theme, ["twentytwenty", "twentynineteen"])) {
			return array(
				'status' => self::CLEAN,
			);
		}
		if (!isset($this->themes[$theme])) {
			return null;
		}
		$relativePath = $this->getRelativePath($home->directory("wp-content/themes/{$theme}"), $file);
		if (in_array($relativePath, ['header.php', 'single.php'])) {
			return null;
		}
		$original = $this->themes[$theme]['version']->file($relativePath);
		if (!$original->exists()) {
			return array(
				"status" => self::INFACTED,
				"action" => self::HANDCHECK,
				'reason' => 'non-exist-theme-file'
			);
		}
		if ($original->md5() != $file->md5()) {
			return array(
				"status" => self::INFACTED,
				"action" => self::HANDCHECK,
				'reason' => 'changed-theme-file'
			);
		}
		return null;
	}

	public function isCleanPluginPHPFile(string $plugin, File $file, Directory $home): ?array {
		if (!isset($this->plugins[$plugin])) {
			return null;
		}
		if (isset($this->plugins[$plugin]['deleted'])) {
			return array(
				"status" => self::INFACTED,
				"action" => self::REMOVE,
				"reason" => "deleted-plugin"
			);
		}
		$relativePath = $this->getRelativePath($home->directory("wp-content/plugins/{$plugin}"), $file);
		$original = $this->plugins[$plugin]['org']->file($relativePath);
		if (!$original->exists()) {
			return array(
				"status" => self::INFACTED,
				"action" => self::REMOVE,
				"reason" => "non-exist-in-plugin"
			);
		}
		if ($original->md5() != $file->md5()) {
			return array(
				"status" => self::INFACTED,
				"action" => self::REPLACE,
				"original" => $original,
				'reason' => 'changed-plugin-file'
			);
		}
		return array(
			"status" => self::CLEAN,
		);
	}

	public function isCleanThemeJSFile(string $theme, File $file, Directory $home): ?array {
		if (in_array($theme, ["twentytwenty", "twentynineteen"])) {
			return array(
				'status' => self::CLEAN,
			);
		}
		if (!isset($this->themes[$theme])) {
			return null;
		}
		$relativePath = $this->getRelativePath($home->directory("wp-content/themes/{$theme}"), $file);
		$original = $this->themes[$theme]['version']->file($relativePath);
		if (!$original->exists()) {
			return array(
				"status" => self::INFACTED,
				"action" => self::HANDCHECK,
				'reason' => 'non-exist-theme-file'
			);
		}
		if ($original->md5() != $file->md5()) {
			return array(
				"status" => self::INFACTED,
				"action" => self::HANDCHECK,
				'reason' => 'changed-theme-file'
			);
		}
		return null;
	}

	public function isCleanPluginJSFile(string $plugin, File $file, Directory $home): ?array {
		if (!isset($this->plugins[$plugin])) {
			return null;
		}
		if (isset($this->plugins[$plugin]['deleted'])) {
			return array(
				"status" => self::INFACTED,
				"action" => self::REMOVE,
				"reason" => "deleted-plugin"
			);
		}
		$relativePath = $this->getRelativePath($home->directory("wp-content/plugins/{$plugin}"), $file);
		$original = $this->plugins[$plugin]['org']->file($relativePath);
		if (!$original->exists()) {
			return array(
				"status" => self::INFACTED,
				"action" => self::REMOVE,
				"reason" => "non-exist-in-plugin"
			);
		}
		if ($original->md5() != $file->md5()) {
			return array(
				"status" => self::INFACTED,
				"action" => self::REPLACE,
				"original" => $original,
				'reason' => 'changed-plugin-file'
			);
		}
		return array(
			"status" => self::CLEAN,
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
		$log = Log::getInstance();
		$log->info(count($this->actions), " actions");
		foreach($this->actions as $item) {
			$item['file'] = isset($item['file']) ? new file($item['file']) : null;
			$item['directory'] = isset($item['directory']) ? new Directory($item['directory']) : null;
			if (isset($item['wp']) and is_string($item['wp'])) {
				$item['wp'] = new WordpressScript(new File($item['wp']));
			}
			if ($item['action'] == self::REPLACE) {
				$item['original'] = new file($item['original']);
				$log->info("Copy {$item['original']->getPath()} to {$item['file']->getPath()}");
				if (!$item['file']->getDirectory()->exists()) {
					$item['file']->getDirectory()->make(true);
				}
				$item['original']->copyTo($item['file']);
				$log->reply("Success");
			} elseif ($item['action'] == self::REMOVE) {
				$target = isset($item['directory']) ? $item['directory'] : $item['file'];
				$log->info("Remove {$target->getPath()}");
				if ($target->exists()) {
					$target->delete(true);
					$log->reply("Success");
				} else {
					$log->reply("Already deleted");
				}
			} elseif ($item['action'] == self::HANDCHECK or $item['action'] == self::EXECUTABLE) {
				if ($item['action'] == self::EXECUTABLE) {
					$log->info("This file is executable {$item['file']->getPath()}:");
					chmod($item['file']->getPath(), 0644);
				} else {
					$log->info("Hand-check {$item['file']->getPath()}");
				}
				do {
					$response = $response = $this->askQuestion("Please check {$item['file']->getRealPath()}", array(
						"OK" => "OK",
						"R" => "Remove",
						"S" => "Show"
					));
					if ($response == "OK") {
						$log->reply("Success");
						break;
					}
					if ($response == "R") {
						$log->reply("Manual Remove");
						if ($item['file']->exists()) {
							$item['file']->delete();
						}
						break;
					}
					if ($response == "S") {
						echo $item['file']->read();
					}
				} while(true);
				if ($item['action'] == self::HANDCHECK and $item['file']->exists()) {
					$this->cleanMd5($item['file']->md5());
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
				} elseif ($item['problem'] == 'nasty-js-virues') {
					$log->info("Repair nasty js virues {$item['file']->getPath()}");
					$content = $item['file']->read();
					$content = preg_replace("/^Element.prototype.appendAfter =.+\)\)\[0\].appendChild\(elem\);}\)\(\);/", "", $content);
					$item['file']->write($content);
				} elseif ($item['problem'] == 'nasty-js-virues-in-php') {
					$log->info("Repair nasty js virues {$item['file']->getPath()}");
					$content = $item['file']->read();
					$content = preg_replace("/<script.*>\s*Element.prototype.appendAfter =.+\)\)\[0\].appendChild\(elem\);}\)\(\);<\/script>/", "", $content);
					$item['file']->write($content);
				} elseif ($item['problem'] == 'injected-lowerbeforwarden-php') {
					$log->info("Repair injected lowerbeforwarden scripts {$item['file']->getPath()}");
					$content = $item['file']->read();
					$content = preg_replace('/^<script .* src=.*<\?php/', "<?php", $content);
					$item['file']->write($content);
				} elseif ($item['problem'] == 'injected-lowerbeforwarden-html') {
					$log->info("Repair injected lowerbeforwarden scripts{$item['file']->getPath()}");
					$content = $item['file']->read();
					$content = preg_replace('/^<script .* src=.*temp\.js.*/', "", $content);
					$item['file']->write($content);
				} elseif ($item['problem'] == 'injected-php-in-first-line') {
					$log->info("Repair injected php in first line {$item['file']->getPath()}");
					$content = $item['file']->read();
					$content = preg_replace('/^<\?php\s{200,}.+eval.+\?><\?php/i', '<?php', $content);
					$item['file']->write($content);
				} elseif ($item['problem'] == 'injected-php-in-first-line-md5') {
					$log->info("Repair injected php in first line (MD5) {$item['file']->getPath()}");
					$content = $item['file']->read();
					$content = preg_replace('/^<\?php.+md5.+\?><\?php/i', '<?php', $content);
					$item['file']->write($content);
				} elseif ($item['problem'] == 'fix-script-in-post-content') {
					$log->info("Repair injected script tag in post content #{$item['post']}");
					$sql = $item['wp']->requireDB();
					$sql->where("ID", $item['post'])
						->update("posts", array(
							"post_content" => $sql->func('REGEXP_REPLACE(`post_content`, "<script.+</script>", "")')
						));
				} elseif ($item['problem'] == 'fix-siteurl') {
					$log->info("Repair changed site url");
					$siteurl = $this->askQuestion("In seems the site url have been changed, please provide a correct one");
					$siteurl = rtrim($siteurl, "/") . "/";
					$item['wp']->setOption("siteurl", $siteurl);
					$item['wp']->setOption("home", $siteurl);
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

	private function preparePlugins(Directory $home): void {
		$log = Log::getInstance();
		$log->info("get plugins and versions");
		$pluginsDir = $home->directory("wp-content/plugins");
		foreach ($pluginsDir->directories(false) as $plugin) {
			$log->info("plugin: {$plugin->basename}");
			do {
				try {
					$this->preparePlugin($home, $plugin);
				} catch (PluginException $e) {
					$question = "";
					$answers = array(
						'R' => 'Retry',
						'S' => 'Skip',
						'D' => 'Delete'
					);
					if ($e->getMessage() == "damaged") {
						$question = $plugin->getPath() . "\nPlugin \"{$plugin->basename}\" is damaged and there's no info block";
					} elseif ($e->getMessage() == "notfound") {
						$question = $plugin->getPath() . "\nPlugin \"{$plugin->basename}\":" . ($e->getInfo()['version'] ?? "undefined") . " is notfound in both jeyserver and wordpress storage";
					}
					if ($question) {
						$response = $this->askQuestion($question, $answers);
						if ($response == 'D') {
							$this->addAction(array(
								'directory' => $plugin->getPath(),
								'action' => self::REMOVE,
							));
							$this->plugins[$plugin->basename] = array(
								'deleted' => true,
							);
						} elseif ($response == 'R') {
							continue;
						}
					}
				}
				break;
			} while(true);
		}
	}

	private function preparePlugin(Directory $home, Directory $plugin) {
		$log = Log::getInstance();
		if ($plugin->isEmpty()) {
			$log->reply("Empty directory, removing it");
			$this->addAction(array(
				'directory' => $plugin->getRealPath(),
				'action' => self::REMOVE,
				"reason" => "empty-plugin"
			));
			return;
		}
		$info = $this->getPluginInfo($plugin);
		if (empty($info)) {
			$log->reply()->warn("it seems plugin is damaged!");
			throw new PluginException($plugin, "damaged");
		}
		$log->reply("version:", $info['version']);

		$log->info("get original plugin");
		$key = $plugin->basename . ($info['version'] ? "-" . $info['version'] : "");
		if (in_array($key, $this->failedPluginDownloads)) {
			$log->reply()->warn("already tried, failed");
			return;
		}
		try {
			$original = WordpressScript::downloadPlugin($plugin->basename, ($info['version'] ?? null));
			if (!$original) {
				$log->reply()->warn("not found!");
				throw new PluginException($plugin, "notfound", $info);
				//$this->failedPluginDownloads[] = $key;
				//continue;
			}
			$this->plugins[$plugin->basename] = array(
				'name' => $info['name'],
				'path' => $info['path'] ?? '',
				'version' => $info['version'] ?? '',
				'org' => $original,
			);
			$log->reply("done");

		} catch (\Exception $e) {
			$log->reply()->warn("not found!");
			throw new PluginException($plugin, "notfound", $info);
			//$this->failedPluginDownloads[] = $key;
		}
		foreach ($original->files(true) as $file) {
			if (!in_array($file->getExtension(), ['php','js'])) {
				continue;
			}
			$relativePath = $this->getRelativePath($original, $file);
			$local = $plugin->file($relativePath);
			if (!$local->exists()) {
				$this->addAction(array(
					'file' => $local->getPath(),
					'action' => self::REPLACE,
					'original' => $file->getRealPath(),
				));
			}
		}
	}
	private function prepareThemes(Directory $home): void {
		$log = Log::getInstance();
		$log->info("get themes");
		$themes = $home->directory("wp-content/themes");
		foreach ($themes->directories(false) as $theme) {
			$log->info($theme->basename);
			$this->prepareTheme($home, $theme);
		}
	}
	private function prepareTheme(Directory $home, Directory $theme) {
		$log = Log::getInstance();
		try {
			$original = WordpressScript::downloadTheme($theme->basename);
			if (!$original) {
				$log->warn("not found!");
				return;
			}
			$versions = $original->directories(false);
			if ($versions) {
				$matches = [];
				foreach ($versions as $versionDir) {
					$matches[$versionDir->basename] = $this->checkMatchesOfTheme($theme, $versionDir);
				}
				asort($matches);
				$version = $original->directory(array_keys($matches)[count($matches) - 1]);
			} else {
				$version = $original;
			}
			$this->themes[$theme->basename] = array(
				'name' => $theme->basename,
				'path' => $theme->basename,
				'org' => $theme,
				'version' => $version,
			);
			$log->info("done, Matched Theme: ", $version->getPath());
		} catch (\Exception $e) {
			$log->warn("not found!");
			return;
		}
		foreach ($version->files(true) as $file) {
			if (!in_array($file->getExtension(), ['php','js'])) {
				continue;
			}
			$relativePath = $this->getRelativePath($version, $file);
			$local = $theme->file($relativePath);
			if (!$local->exists()) {
				$this->addAction(array(
					'file' => $local->getPath(),
					'action' => self::REPLACE,
					'original' => $file->getRealPath(),
				));
			}
		}
	}
	private function checkMatchesOfTheme(Directory $theme, Directory $version): int {
		$matches = 0;
		foreach ($version->files(true) as $file) {
			$relativePath = $this->getRelativePath($version, $file);
			$local = $theme->file($relativePath);
			if ($local->exists() and $local->md5() == $file->md5()) {
				$matches++;
			}
		}
		return $matches;
	}

	private function getRelativePath(Directory $base, File $file): string {
		return substr($file->getPath(), strlen($base->getPath()) + 1);
	}

	private function askQuestion(string $question, ?array $answers = null): string {
		do {
			$helpToAsnwer = "";
			if ($answers) {
				foreach ($answers as $shortcut => $answer) {
					if ($helpToAsnwer) {
						$helpToAsnwer .= ", ";
					}
					$shutcut = strtoupper($shortcut);
					$helpToAsnwer .= ($answer != $shutcut ? $shortcut . " = " : "") . $answer;
				}
			}
			echo($question . ($helpToAsnwer ? " [{$helpToAsnwer}]" : "") . ": ");
			$response = trim(fgets(STDIN));
			if ($answers) {
				$response = strtoupper($response);
				$shutcuts = array_map('strtoupper', array_keys($answers));
				if (in_array($response, $shutcuts)) {
					return $response;
				}
			} elseif ($response) {
				return $response;
			}
		}while(true);
	}
	private function checkFileContent(array $rules, File $file, ?string $content = null): ?array {
		if ($content === null) {
			$content = $file->read();
		}

		$hotcheck = false;
		$highlights = [];
		foreach($rules as $rule) {
			switch ($rule['type']) {
				case "exact":
					$valid = stripos($content, $rule['needle']) !== false;
					break;
				case "pattern":
					$valid = preg_match($rule['needle'], $content, $matches) > 0;
					break;
				default:
					$valid = false;
			}
			if ($valid) {
				if ($rule['action'] == 'hotcheck') {
					$hotcheck = true;
					if ($rule['type'] == "exact") {
						$highlights[] = $rule['needle'];
					} elseif ($rule['type'] == "pattern") {
						$highlights[] = $matches[0];
					}
				} else {
					$result = array(
						'status' => self::INFACTED,
						'action' => $rule['action'],
						'reason' => $rule['reason'] ?? 'check-content',
						'needle' => $rule['needle'],
					);
					if ($rule['action'] == self::REPAIR) {
						$result['problem'] = $rule['problem'];
					}
					return $result;
				}
			}
		}
		$md5 = md5($content);
		if ($hotcheck and !$this->isCleanMd5($md5)) {
			while(true) {
				$response = $this->askQuestion($file->getPath()."\nIs this file is clean?", array(
					'S' => 'Show Content',
					'Y' => 'Clean',
					'N' => 'Infacted',
				));
				if ($response == "S") {
					echo $this->highlight($highlights, $content)."\n";
				} else if ($response == "Y") {
					$this->cleanMd5($md5);
					return array(
						'status' => self::CLEAN,
					);
				} else if ($response == "N") {
					$this->infactedMd5($md5);
					return array(
						'status' => self::INFACTED,
						'action' => self::REMOVE,
						"reason" => "manual-hotcheck"
					);
				}
			}
		}
		return null;
	}
}
