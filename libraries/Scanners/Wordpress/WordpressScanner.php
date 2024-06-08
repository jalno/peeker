<?php

namespace packages\peeker\Scanners\Wordpress;

use packages\base\Exception;
use packages\base\IO\Directory;
use packages\base\IO\File;
use packages\base\Log;
use packages\peeker\ActionConflictException;
use packages\peeker\ActionManager;
use packages\peeker\Actions;
use packages\peeker\Actions\RemoveDirectory;
use packages\peeker\Actions\RemoveFile;
use packages\peeker\Actions\Wordpress\ResetWPRocketCache;
use packages\peeker\Actions\Wordpress\ScriptInPostsContentRepair;
use packages\peeker\FileScannerTrait;
use packages\peeker\IAction;
use packages\peeker\Scanner;
use packages\peeker\Scanners\DirectoryChangesScanner;
use packages\peeker\WordpressDownloader;
use packages\peeker\WordpressScript;

class WordpressScanner extends Scanner
{
    use FileScannerTrait;

    protected Directory\Local $originalWP;
    protected array $badDirectories = [
        'wp-snapshots',
        '.quarantine',
        '.tmb',
        '.well-known',
        'cgi-bin',
        'wp-content/cache',
        'wp-content/upgrades',
    ];
    protected bool $hasInfacted = false;

    public function __construct(ActionManager $actions, protected WordpressScript $script)
    {
        parent::__construct($actions, $script->getHome());
    }

    public function prepare(): void
    {
        $log = Log::getInstance();
        $log->info('prepare original version');
        $this->prepareOriginal();
    }

    public function scan(): void
    {
        if (!isset($this->originalWP)) {
            throw new Exception('original version of wordpress notfound');
        }

        $log = Log::getInstance();
        $log->debug('scan files based on orignal version of wordpress');
        $this->scanFiles();
        $log->reply('Success');

        try {
            $log->debug('scan site url');
            $this->scanSiteUrl();
            $log->reply('Success');
        } catch (\Exception $e) {
            $log->reply()->error($e->__toString());
        }
        try {
            $log->debug('scan posts content');
            $this->scanPostsContent();
            $log->reply('Success');
        } catch (\Exception $e) {
            $log->reply()->error($e->__toString());
        }

        if ($this->hasInfacted) {
            $this->actions->add((new ResetWPRocketCache($this->home))->setReason('infacted-wordpress'));
        }
    }

    protected function prepareOriginal(): void
    {
        $log = Log::getInstance();
        $log->info('get wp version');
        $version = $this->script->getWPVersion();
        if (!$version) {
            $log->reply()->fatal('not found');
            throw new Exception('cannot find wordpress version');
        }
        $log->reply($version);
        $log->info('download original version');
        $this->originalWP = WordpressDownloader::getInstance()->version($version);
        $log->reply('saved in', $this->originalWP->getPath());
    }

    protected function scanFiles(): void
    {
        $log = Log::getInstance();

        $log->info('Scan Wordpress Core');
        $this->scanWordpressCore();
        $log->reply('Done');

        $log->info('Remove Bad Directories');
        $this->removeBadDirectories();
        $log->reply('Done');

        $log->info('Remove Bad Files');
        $files = $this->getFiles($this->home);
        foreach ($files as $file) {
            $path = $file->getRelativePath($this->home);
            $log->debug('check', $path);
            $this->scanFile($file);
        }
    }

    protected function scanWordpressCore(): void
    {
        foreach (['wp-admin', 'wp-includes'] as $dir) {
            $home = $this->home->directory($dir);
            $original = $this->originalWP->directory($dir);
            $scanner = new DirectoryChangesScanner($this->actions, $home, $original);
            $scanner->scan();
        }

        $scanner = new DirectoryChangesScanner($this->actions, $this->home, $this->originalWP);
        $scanner->setExtensions(['php']);
        $scanner->setIgnorePaths(['wp-content/themes/', 'wp-content/plugins/', 'wp-config.php']);
        $scanner->scan();
    }

    protected function removeBadDirectories(): void
    {
        foreach ($this->badDirectories as $path) {
            $directory = $this->home->directory($path);
            if ($directory->exists()) {
                $this->actions->add((new RemoveDirectory($directory))->setReason('bad-directory-in-wp'));
            }
        }
    }

    protected function scanFile(File $file): void
    {
        $log = Log::getInstance();
        $action = $this->checkFile($file);
        if (!$action) {
            return;
        }
        $isClean = $action instanceof Actions\CleanFile;
        if (!$isClean) {
            $path = $file->getRelativePath($this->home);
            $log->info($path, 'Infacted, Reason:', $action->getReason());
        }
        try {
            $this->actions->add($action);
            if (!$isClean) {
                $this->hasInfacted = true;
            }
        } catch (ActionConflictException $conflict) {
            $old = $conflict->getOldAction();
            if (
                !$old instanceof Actions\CleanFile
                and !$old instanceof Actions\Repair
                and !$old instanceof Actions\ReplaceFile
                and !$old instanceof Actions\HandCheckFile
            ) {
                $this->actions->delete($old);
                $this->actions->add((new Actions\HandCheckFile($file))->setReason('resolving-conflict'));
            }
        }
    }

    public function checkFile(File $file): ?IAction
    {
        $path = $file->getRelativePath($this->home);

        if ($this->isFileInBadDirectory($path)) {
            return null;
        }

        $ext = $file->getExtension();
        if ('ico' == $ext and '.' == substr($file->basename, 0, 1)) {
            return (new RemoveFile($file))->setReason('infacted-ico-file');
        }
        if ('suspected' == $ext) {
            return (new RemoveFile($file))->setReason('suspected-file');
        }
        if ('shtml' == $ext) {
            return (new RemoveFile($file))->setReason('shtml-file');
        }
        if ('log.txt' == $file->basename or 'log.zip' == $file->basename) {
            return (new RemoveFile($file))->setReason('bad-name');
        }

        if (in_array($file->basename, ['php.ini', '.user.ini'])) {
            $content = $file->read();
            if (preg_match('/(exec|basedir|safe_mode|disable_)/', $content)) {
                return (new RemoveFile($file))->setReason('infacted-ini-file');
            }
        }

        if (preg_match("/^wp-content\/themes\/([^\/]+)/", $path, $matches) and ThemeScanner::isOriginalTheme($matches[1])) {
            return null;
        }

        if (preg_match("/^wp-content\/(languages|upgrade|uploads|mu-plugins)\/.+\.html$/", $path)) {
            return (new RemoveFile($file))
                ->setReason('non-original-core-wordpress-file');
        }

        if (preg_match("/^wp-content\/mu-plugins\/(.+)$/", $path, $matches) and 'autoupdate.php' != $matches[1]) {
            return (new RemoveFile($file))->setReason('unknown-mu-plugins');
        }
        if (
            preg_match("/^wp-content\/wp-rocket-config\//", $path)
            and !preg_match("/^wp-content\/wp-rocket-config\/(?:[a-z0-9-]+\.)+[a-z]+\.php$/", $path, $matches)
        ) {
            return (new RemoveFile($file))->setReason('suspicious-wp-rocket-config');
        }

        return null;
    }

    protected function scanSiteUrl(): void
    {
        foreach ([$this->script->getOption('siteurl'), $this->script->getOption('home')] as $item) {
            if (false !== strpos($item, '?') || false !== strpos($item, '&')) {
                $this->actions->add(new Actions\Wordpress\SiteURLRepair($this->script));
                $this->hasInfacted = true;
                break;
            }
        }
    }

    protected function scanPostsContent(): void
    {
        $sql = $this->script->requireDB();
        $posts = array_column($sql->where('post_content', '<script', 'contains')->get('posts', null, ['ID']), 'ID');
        foreach ($posts as $post) {
            $this->actions->add(new ScriptInPostsContentRepair($this->script, $post));
            $this->hasInfacted = true;
        }
    }

    private function isFileInBadDirectory(string $path): bool
    {
        foreach ($this->badDirectories as $dir) {
            if (str_starts_with($path, "{$dir}/")) {
                return true;
            }
        }

        return false;
    }
}
