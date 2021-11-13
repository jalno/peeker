<?php
namespace packages\peeker\scanners\wordpress;

use packages\base\{IO\File, IO\Directory, http\Client, Exception, Log, Packages};
use packages\peeker\{ActionManager, WordpressScript, Scanner, IAction, FileScannerTrait, ActionConflictException, actions};

class WordpressScanner extends Scanner {
	use FileScannerTrait;

	protected $script;
	protected $originalWP;
	protected $hasInfacted = false;

	public function __construct(ActionManager $actions, WordpressScript $script) {
		parent::__construct($actions, $script->getHome());
		$this->script = $script;
	}

	public function prepare(): void {
		$log = Log::getInstance();
		$log->info("prepare original version");
		$this->prepareOriginal();
	}

	public function scan(): void {
		$log = Log::getInstance();

		$log->debug("scan files");
		$this->scanFiles();
		$log->reply("Success");

		try {
			$log->debug("scan site url");
			$this->scanSiteUrl();
			$log->reply("Success");
		} catch (\Exception $e) {
			$log->reply()->error($e->__toString());
		}
		try {	
			$log->debug("scan posts content");
			$this->scanPostsContent();
			$log->reply("Success");
		} catch (\Exception $e) {
			$log->reply()->error($e->__toString());
		}

		if ($this->hasInfacted) {
			$this->actions->add((new actions\wordpress\ResetWPRocketCache($this->home))->setReason("infacted-wordpress"));
		}
	}

	protected function prepareOriginal(): void {
		$log = Log::getInstance();
		$log->info("get wp version");
		$version = $this->script->getWPVersion();
		if (!$version) {
			$log->reply()->fatal("not found");
			throw new Exception("cannot find wordpress version");
		}
		$log->reply($version);
		$log->info("download original version");
		$this->originalWP = $this->download($version);
		$log->reply("saved in", $this->originalWP->getPath());
	}

	protected function scanFiles(): void {
		$log = Log::getInstance();

		$files = $this->getFiles($this->home);
		foreach ($files as $file) {
			$path = $file->getRelativePath($this->home);
			$log->debug("check", $path);
			$this->scanFile($file);
		}
	}
	protected function scanFile(File $file): void {
		$log = Log::getInstance();
		$action = $this->checkFile($file);
		if (!$action) {
			return;
		}
		$isClean = $action instanceof actions\CleanFile;
		if (!$isClean) {
			$path = $file->getRelativePath($this->home);
			$log->info($path, "Infacted, Reason:", $action->getReason());
		}
		try {
			$this->actions->add($action);
			if (!$isClean) {
				$this->hasInfacted = true;
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
	public function checkFile(File $file): ?IAction {
		$log = Log::getInstance();

		$ext = $file->getExtension();
		if ($ext == "ico" and substr($file->basename, 0, 1) == ".") {
			return (new actions\RemoveFile($file))
				->setReason('infacted-ico-file');
		}
		if ($ext == "suspected") {
			return (new actions\RemoveFile($file))
				->setReason('suspected-file');
		}
		if ($ext == "shtml") {
			return (new actions\RemoveFile($file))
				->setReason('shtml-file');
		}
		if ($file->basename == "log.txt" or $file->basename == "log.zip") {
			return (new actions\RemoveFile($file))
				->setReason('bad-name');
		}

		$path = $file->getRelativePath($this->home);

		if (preg_match("/^wp-content\/themes\/([^\/]+)/", $path, $matches) and ThemeScanner::isOriginalTheme($matches[1])) {
			return null;
		}
		$original = $this->originalWP->file($path);
		if (!$original->exists()) {
			if (preg_match("/^wp-snapshots\//", $path)) {
				return (new actions\RemoveFile($file))
					->setReason('snapshots-file');
			}
			if (preg_match("/^\.quarantine\//", $path)) {
				return (new actions\RemoveFile($file))
					->setReason('.quarantine-file');
			}
			if (preg_match("/^\.tmb\//", $path)) {
				return (new actions\RemoveFile($file))
					->setReason('.tmb-file');
			}
			if (preg_match("/^\.well-known\//", $path)) {
				return (new actions\RemoveFile($file))
					->setReason('.well-known-file');
			}
			if (preg_match("/^wp-(includes|admin)\//", $path)) {
				return (new actions\RemoveFile($file))
					->setReason('non-original-core-wordpress-file');
			}
			if (preg_match("/^wp-content\/(cache|languages|upgrade|uploads)\/.+\.php$/", $path)) {
				return (new actions\RemoveFile($file))
					->setReason('php-file-in-forbidden-directories');
			}
			if (preg_match("/^wp-content\/(languages|upgrade|uploads|mu-plugins)\/.+\.html$/", $path)) {
				return (new actions\RemoveFile($file))
					->setReason('non-original-core-wordpress-file');
			}
			if (preg_match("/^wp-content\/mu-plugins\/(.+)$/", $path, $matches) and $matches[1] != "autoupdate.php") {
				return (new actions\RemoveFile($file))
					->setReason('unknown-mu-plugins');
			}
			if (
				preg_match("/^wp-content\/wp-rocket-config\//", $path) and
				!preg_match("/^wp-content\/wp-rocket-config\/(?:[a-z0-9-]+\.)+[a-z]+\.php$/", $path, $matches)
			) {
				return (new actions\RemoveFile($file))
					->setReason('suspicious-wp-rocket-config');
			}
			if (
				preg_match("/^wp-content\/.+\.php$/", $path) and
				!preg_match("/^wp-content\/(mu-plugins\/.+|themes\/.+|plugins\/.+|wp-rocket-config\/.+|advanced-cache|index)\.php$/", $path)
			) {
				return (new actions\RemoveFile($file))
					->setReason('extra-php-file-in-wp-content');
			}
			if ($ext == "php" and !preg_match("/^(wp-(admin|includes|content)\/.*)?[^\/]+\.php$/", $path)) {
				return (new actions\RemoveFile($file))
					->setReason('extra-php-in-wordpress-home');
			}
			if (in_array($file->basename, ['php.ini', '.user.ini'])) {
				$content = $file->read();
				if (preg_match("/(exec|basedir|safe_mode|disable_)/", $content)) {
					return (new actions\RemoveFile($file))
						->setReason('infacted-ini-file');
				}
			}
			return null;
		}
		if ($original->md5() != $file->md5()) {
			return (new actions\ReplaceFile($file, $original))
				->setReason("replace-wordpress-file");
		}
		return (new actions\CleanFile($file))
			->setReason("wordpress-original-file");
	}

	protected function download(string $version): Directory {
		$repo = Packages::package('peeker')->getHome()->directory("storage/private/wordpress-versions/{$version}");
		$src = $repo->directory("wordpress");
		if ($src->exists()) {
			return $src;
		}
		if (!$repo->exists()) {
			$repo->make(true);
		}
		$zipFile = new File\Tmp();
		(new Client())->get("https://wordpress.org/wordpress-{$version}.zip", array(
			'save_as' => $zipFile
		));
		$zip = new \ZipArchive();
		if ($zip->open($zipFile->getPath()) === false) {
			throw new Exception("cannot open zip file");
		}
		$zip->extractTo($repo->getPath());
		$zip->close();
		return $src;
	}

	protected function scanSiteUrl(): void {
		foreach ([$this->script->getOption("siteurl"), $this->script->getOption("home")] as $item) {
			if (strpos($item, "?") !== false || strpos($item, "&") !== false) {
				$this->actions->add(new actions\wordpress\SiteURLRepair($this->script));
				$this->hasInfacted = true;
				break;
			}
		}
	}
	protected function scanPostsContent(): void {
		$sql = $this->script->requireDB();
		$posts = array_column($sql->where("post_content", "<script", "contains")->get("posts", null, ['ID']), 'ID');
		foreach ($posts as $post) {
			$this->actions->add(new actions\wordpress\ScriptInPostsContentRepair($this->script, $post));
			$this->hasInfacted = true;
		}
	}
}
