<?php
namespace packages\peeker\scanners\wordpress;

use packages\base\{Log, IO\File, IO\Directory, Packages, http\Client};
use packages\peeker\{IAction, Scanner, FileScannerTrait, ActionConflictException, actions};

class ThemeScanner extends Scanner {
	public static function isOriginalTheme(string $theme): bool {
		return in_array($theme, array(
			'twentytwenty',
			'twentynineteen',
			'twentyseventeen',
			'twentysixteen',
			'twentyfifteen',
			'twentyfourteen',
			'twentythirteen',
			'twentytwelve',
			'twentyeleven',
			'twentyten'
		));
	}
	use FileScannerTrait;

	protected $themes = [];

	public function prepare(): void {
		$log = Log::getInstance();

		$themes = $this->findThemes();
		foreach ($themes as $theme) {
			$this->prepareTheme($theme);
		}
	}

	public function scan(): void {
		if (!$this->themes) {
			return;
		}
		foreach ($this->themes as $theme) {
			$files = $this->getFiles($theme['directory'], ['js', 'php']);
			$this->scanTheme($theme, $files);
		}
	}

	protected function scanTheme(array $theme, \Iterator $files): void {
		$log = Log::getInstance();
		foreach ($files as $file) {
			$action = $this->checkFile($theme, $file);
			if (!$action) {
				continue;
			}
			$isClean = $action instanceof actions\CleanFile;
			if (!$isClean) {
				$path = $file->getRelativePath($this->home);
				$log->info($path, "Infacted, Reason:", $action->getReason());
			}
			try {
				$this->actions->add($action);
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
	}

	protected function checkFile(array $theme, File $file): ?IAction {
		$isOriginalTheme = self::isOriginalTheme($theme['directory']->basename);
		$path = $file->getRelativePath($theme['directory']);
		if (in_array($path, ['header.php', 'single.php'])) {
			return null;
		}
		$original = $theme['original']->file($path);
		if (!$original->exists()) {
			if ($isOriginalTheme) {
				return (new actions\RemoveFile($file))
					->setReason('non-exist-original-theme-file');
			}
			return (new actions\HandCheckFile($file))
				->setReason('non-exist-theme-file');
		}
		if ($original->md5() != $file->md5()) {
			if ($isOriginalTheme) {
				return (new actions\ReplaceFile($file, $original))
					->setReason('changed-original-theme-file');
			}
			return (new actions\HandCheckFile($file))
				->setReason('changed-theme-file')
				->setOriginalFile($original);
		}
		return (new actions\CleanFile($file))
			->setReason('original-theme-file');
	}

	protected function prepareTheme(Directory $theme) {
		$log = Log::getInstance();
		try {
			$original = $this->download($theme->basename);
			if (!$original) {
				$log->warn("not found!");
				return;
			}
			$versions = $original->directories(false);
			if ($versions and !$original->files(false)) {
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
				'original' => $version,
				'directory' => $theme,
			);
			$log->info("done, Matched Theme: ", $version->getPath());
		} catch (\Exception $e) {
			$log->warn("not found!");
			return;
		}
		foreach ($this->getFiles($version, ['js', 'php']) as $file) {
			$path = $file->getRelativePath($version);
			$local = $theme->file($path);
			if (!$local->exists()) {
				$this->actions->add((new actions\ReplaceFile($local, $file))->setReason("missing theme file"));
			}
		}
	}

	protected function checkMatchesOfTheme(Directory $theme, Directory $version): int {
		$matches = 0;
		foreach ($version->files(true) as $file) {
			$path = $file->getRelativePath($version);
			$local = $theme->file($path);
			if ($local->exists() and $local->md5() == $file->md5()) {
				$matches++;
			}
		}
		return $matches;
	}

	protected function findThemes(?Directory $directory = null): \Iterator {
		if ($directory == null) {
			$directory = $this->home;
		}
		$directories = $directory->directories(false);
		foreach ($directories as $item) {
			if ($item->basename == "themes" and $directory->basename == "wp-content") {
				yield from $item->directories(false);
			} elseif (!in_array($item->basename, [".quarantine", ".tmb", ".well-known", "cgi-bin", "wp-admin", "wp-includes", "busting"])) {
				yield from $this->findThemes($item);
			}
		}
	}

	protected function download(string $name): ?Directory {
		$log = Log::getInstance();
		$log->info("try find or download theme: {$name}");
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
		$log->info("downloading theme");
		$http = new Client(array(
			"base_uri" => "http://peeker.jeyserver.com/",
		));
		$zipFile = new File\Tmp();
		try {
			$http->get("themes/{$name}.zip", array(
				"save_as" => $zipFile
			));
		} catch (\Exception $e) {
			$log->reply()->warn("failed!");
			return null;
		}
		$zip = new \ZipArchive();
		$open = $zip->open($zipFile->getPath());
		if ($open !== true) {
			throw new \Exception("Cannot open zip file: " . $open);
		}
		$zip->extractTo($src->getPath());
		$zip->close();

		return $src;
	}
}
