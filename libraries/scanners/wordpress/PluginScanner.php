<?php
namespace packages\peeker\scanners\wordpress;

use packages\base\{Log, IO\File, IO\Directory, Packages, http\Client, Exception};
use packages\peeker\{IAction, Scanner, FileScannerTrait, ActionConflictException, WordpressScript, actions};

class PluginScanner extends Scanner {

	public static function checkPlugin(Directory $plugin): Directory {
		if ($plugin->isEmpty()) {
			throw new PluginException("Empty directory", 101);
		}
		$info = WordpressScript::getPluginInfo($plugin);
		if (empty($info)) {
			throw new PluginException("Plugin damaged", 102);
		}
		if (!isset($info['version'])) {
			$info['version'] = null;
		}
		$key = $plugin->basename . ($info['version'] ? "-" . $info['version'] : "");
		try {
			$original = self::download($plugin->basename, $info['version']);
			if (!$original) {
				throw new PluginException("Plugin Original Not found", 103, $info['version']);
			}
			return $original;
		} catch (\Exception $e) {
			throw new PluginException("Plugin Original Not found", 103, $info['version']);
		}
	}

	protected static function download(string $pluginName, string $version = null, bool $fallback = true): ?Directory {
		$log = Log::getInstance();
		$name = $pluginName;
		if (substr($name, -strlen("-master")) == "-master") {
			$name = substr($name, 0, -strlen("-master"));
		}
		$log->info("try find or download plugin: {$name}, version:", $version, ", fallback:", ($fallback ? "yes" : "no"));
		$repo = Packages::package("peeker")->getHome()->directory("storage/private/plugins");
		if (!$repo->exists()) {
			$repo->make(true);
		}
		$src = $repo->directory($name);
		if (!$src->exists()) {
			$src->make();
		}
		$latest = $src->directory("latest");
		$requestedVersionSrc = ($version ? $src->directory($version) : null);
		if ($requestedVersionSrc) {
			if ($requestedVersionSrc->exists()) {
				if (!$requestedVersionSrc->isEmpty()) {
					return $requestedVersionSrc;
				}
				if (!$fallback) {
					return null;
				}
			} else {
				$requestedVersionSrc->make();
			}
		}
		if (!$requestedVersionSrc) {
			if ($latest->exists()) {
				return !$latest->isEmpty() ? $latest : null;
			} else {
				$latest->make();
			}
		}

		$zipFile = new File\Tmp();
		$fileName = ($version ? "{$name}.{$version}" : $name);
		$log->debug("download file: {$fileName}");
		$log->debug("start with peeker.jeyserver.com mirror");
		try {
			$response = (new Client)->get("http://peeker.jeyserver.com/plugins/{$fileName}.zip", array(
				"save_as" => $zipFile,
			));
			if ($response->getStatusCode() != 200) {
				throw new \Exception("http_status_code");
			}
			$log->reply("done");
		} catch (\Exception $e) {
			$log->reply("failed!", $e->getMessage());
			$log->debug("switch to downloads.wordpress.org");
			try {
				$response = (new Client)->get("https://downloads.wordpress.org/plugin/{$fileName}.zip", array(
					"save_as" => $zipFile,
				));
				if ($response->getStatusCode() != 200) {
					throw new Exception("http_status_code");
				}
				$log->reply("done");
			} catch (\Exception $e) {
				$log->reply("failed!", $e->getMessage());
				if ($requestedVersionSrc) {
					$requestedVersionSrc->delete(true);
				} else {
					$latest->delete(true);
				}
				if (!$src->files(true)) {
					$src->delete(true);
				}
				if ($requestedVersionSrc and $fallback) {
					$log->debug("try to fallback to find latest version");
					return self::download($name, null, false);
				}
				return null;
			}
		}
		$zip = new \ZipArchive();
		$open = $zip->open($zipFile->getPath());
		if ($open !== true) {
			throw new Exception("Cannot open zip file: " . $open);
		}
		$resultDirectory = ($requestedVersionSrc ? $requestedVersionSrc : $latest);
		$zip->extractTo($resultDirectory->getPath());
		$zip->close();

		$sameNameDirectory = $resultDirectory->directory($pluginName);
		if ($sameNameDirectory->exists()) {
			$sameNameDirectory->move($resultDirectory->getDirectory());
			$resultDirectory->getDirectory()->directory($pluginName)->rename($resultDirectory->basename);
		}

		return $resultDirectory;
	}

	use FileScannerTrait;

	protected $plugins = [];
	
	public function prepare(): void {
		$log = Log::getInstance();

		$plugins = $this->findPlugins();
		foreach ($plugins as $plugin) {
			$this->preparePlugin($plugin);
		}
	}

	public function scan(): void {
		if (!$this->plugins) {
			return;
		}
		foreach ($this->plugins as $plugin) {
			$files = $this->getFiles($plugin['directory'], ['php', 'js', 'html']);
			$this->scanPlugin($plugin, $files);
		}
	}

	protected function scanPlugin(array $plugin, \Iterator $files) {
		$log = Log::getInstance();
		$hasInfacted = false;
		foreach ($files as $file) {
			$action = $this->checkFile($plugin, $file);
			$isClean = $action instanceof actions\CleanFile;
			if (!$isClean) {
				$path = $this->home->getRelativePath($file);
				$log->info($path, "Infacted, Reason:", $action->getReason());
			}
			try {
				$this->actions->add($action);
				if (!$isClean) {
					$hasInfacted = true;
				}
			} catch (ActionConflictException $conflict) {
				$old = $conflict->getOldAction();
				if (
					!$old instanceof actions\CleanFile and
					!$old instanceof actions\Repair and
					!$old instanceof actions\ReplaceFile and
					!$old instanceof actions\HandCheckFile
				) {
					$this->actions->delete($old);
					$this->actions->add((new actions\HandCheckFile($file))->setReason("resolving-conflict"));
				}
			}
		}
		if ($hasInfacted) {
			$this->actions->add((new actions\wordpress\ResetWPRocketCache($this->home))->setReason("infacted-wordpress-plugin"));
		}
	}

	protected function checkFile(array $plugin, File $file): IAction {
		$path = $plugin['directory']->getRelativePath($file);
		$original = $plugin['original']->file($path);
		if (!$original->exists()) {
			return (new actions\RemoveFile($file))
				->setReason('non-exist-plugin-file');
		}
		if ($original->md5() != $file->md5()) {
			return (new actions\ReplaceFile($file, $original))
				->setReason('changed-plugin-file');
		}
		return (new actions\CleanFile($file))
			->setReason('original-plugin-file');
	}

	protected function preparePlugin(Directory $plugin): void {
		$log = Log::getInstance();
		try {
			$path = $this->home->getRelativePath($plugin);
			$log->info("Check " . $path);
			$original = self::checkPlugin($plugin);
			$this->plugins[$plugin->basename] = array(
				'directory' => $plugin,
				'original' => $original,
			);
			$log->reply("done");

			foreach ($this->getFiles($original) as $file) {
				$local = $plugin->file($original->getRelativePath($file));
				if (!$local->exists()) {
					$this->actions->add((new actions\ReplaceFile($local, $file))->setReason("missing plugin file"));
				}
			}
		} catch(PluginException $e) {
			$log->reply()->error($e->getMessage());
			switch ($e->getCode()) {
				case 101:
					$log->reply("removing it");
					$this->actions->add((new actions\RemoveDirectory($plugin))->setReason("empty-plugin"));
					break;
				case 102:
					$this->actions->add((new actions\wordpress\HandCheckPlugin($plugin, $e->getVersion()))->setReason("plugin-damaged"));
					break;
				case 103:
					$this->actions->add((new actions\wordpress\HandCheckPlugin($plugin, $e->getVersion()))->setReason("plugin-notfound"));
					break;
			}
		}
	}

	protected function findPlugins(?Directory $directory = null): \Iterator {
		if ($directory == null) {
			$directory = $this->home;
		}
		$directories = $directory->directories(false);
		foreach ($directories as $item) {
			if ($item->basename == "plugins" and $directory->basename == "wp-content") {
				yield from $item->directories(false);
			} elseif (!in_array($item->basename, [".quarantine", ".tmb", ".well-known", "cgi-bin", "wp-admin", "wp-includes"])) {
				yield from $this->findPlugins($item);
			}
		}
	}

}
