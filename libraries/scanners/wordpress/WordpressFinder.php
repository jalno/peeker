<?php
namespace packages\peeker\scanners\wordpress;

use packages\base\{Log, IO\File, IO\Directory};
use packages\peeker\{Scanner, WordpressScript};

class WordpressFinder extends Scanner {
	protected $scanners = [];

	public function prepare(): void {
		$log = Log::getInstance();
		$log->info("looking for wp-config.phps");
		$configs = $this->findWPConfigs();
		if (!$configs) {
			$log->reply("Found none");
			return;
		}
		$log->reply(count($configs), "found");
		foreach ($configs as $config) {
			$log->info($config->getPath());
			try {
				$scanner = new WordpressScanner($this->actions, new WordpressScript($config));
				$log->info("prepare");
				$scanner->prepare();
				$this->scanners[] = $scanner;
			} catch (\Exception $e) {
				$log->reply()->error($e->__toString());
			}
		}
	}

	public function scan(): void {
		foreach ($this->scanners as $scanner) {
			$scanner->scan();
		}
	}

	protected function findWPConfigs(?Directory $root = null): array {
		if (!$root) {
			$root = $this->home;
		}
		$configs = [];
		$ignoreDirs = [".quarantine", ".tmb", ".well-known", "cgi-bin", "wp-admin", "wp-includes", "wp-content"];
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
}
