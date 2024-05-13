<?php

namespace packages\peeker\scanners\wordpress;

use packages\base\IO\Directory;
use packages\base\Log;
use packages\base\Packages;
use packages\peeker\actions\wordpress\ResetWPRocketCache;
use packages\peeker\Scanner;
use packages\peeker\Scanners\DirectoryChangesScanner;
use packages\peeker\WordpressDownloader;
use packages\peeker\WordpressScript;

class ThemeScanner extends Scanner
{
    public static function isOriginalTheme(string $theme): bool
    {
        return in_array($theme, [
            'twentytwentysix',
            'twentytwentyfive',
            'twentytwentyfour',
            'twentytwentythree',
            'twentytwentytwo',
            'twentytwentyone',
            'twentytwenty',
            'twentynineteen',
            'twentyseventeen',
            'twentysixteen',
            'twentyfifteen',
            'twentyfourteen',
            'twentythirteen',
            'twentytwelve',
            'twentyeleven',
            'twentyten',
        ]);
    }

    /**
     * @var array<string,array{original:Directory\Local,directory:Directory}>
     */
    protected array $themes = [];

    public function prepare(): void
    {
        $log = Log::getInstance();

        $themes = $this->findThemes();
        foreach ($themes as $theme) {
            $log->info('prepare theme', $theme->basename);
            try {
                $this->prepareTheme($theme);
                $log->reply('Done');
            } catch (\Exception $e) {
                $log->reply()->error($e->__toString());
            }
        }
    }

    public function scan(): void
    {
        if (!$this->themes) {
            return;
        }
        $log = Log::getInstance();

        foreach ($this->themes as $theme) {
            $log->info("Compare theme with it's original:", $theme['directory']->getRelativePath($this->home));
            $this->scanTheme($theme['directory'], $theme['original']);
        }
    }

    protected function scanTheme(Directory $input, Directory\Local $original): void
    {
        $scanner = new DirectoryChangesScanner($this->actions, $input, $original);
        $scanner->setExtensions(['php', 'js']);
        $scanner->scan();
        if ($scanner->isInfacted()) {
            $this->actions->add((new ResetWPRocketCache($this->home))->setReason('infacted-wordpress-theme'));
        }
    }

    protected function prepareTheme(Directory $theme): void
    {
        $log = Log::getInstance();
        $info = WordpressScript::getThemeInfo($theme);

        $version = null;

        if (isset($info['version'])) {
            try {
                $log->info('Try to donwload theme with version', $info['version']);
                $version = WordpressDownloader::getInstance()->theme($theme->basename, $info['version']);
                $log->reply('Done');
            } catch (\Exception $e) {
                $log->reply()->error('failed');
            }
        }
        if (!$version) {
            $log->info('try to find theme from cache directory');
            $original = Packages::package('peeker')->getStorage('private')->directory('themes/'.$theme->basename);
            if (!$original->exists()) {
                $log->reply()->fatal('notfound');

                return;
            }
            $versions = $original->directories(false);
            if ($versions and !$original->files(false)) {
                $log->reply('Found', count($versions), 'versions');
                $matches = [];
                foreach ($versions as $versionDir) {
                    $matches[$versionDir->basename] = $this->checkMatchesOfTheme($theme, $versionDir);
                }
                asort($matches);
                $version = $original->directory(array_keys($matches)[count($matches) - 1]);
            } else {
                $log->reply('Found', 1, 'versions');
                $version = $original;
            }
            $log->info('matched version: ', $version->getPath());
        }
        $this->themes[$theme->basename] = [
            'original' => $version,
            'directory' => $theme,
        ];
    }

    protected function checkMatchesOfTheme(Directory $theme, Directory $original): int
    {
        $matches = 0;
        foreach ($original->files(true) as $file) {
            $path = $file->getRelativePath($original);
            $local = $theme->file($path);
            if ($local->exists() and $local->md5() == $file->md5()) {
                ++$matches;
            }
        }

        return $matches;
    }

    protected function findThemes(?Directory $directory = null): \Iterator
    {
        if (null == $directory) {
            $directory = $this->home;
        }
        $directories = $directory->directories(false);
        foreach ($directories as $item) {
            if ('themes' == $item->basename and 'wp-content' == $directory->basename) {
                yield from $item->directories(false);
            } elseif (!in_array($item->basename, ['.quarantine', '.tmb', '.well-known', 'cgi-bin', 'wp-admin', 'wp-includes', 'busting'])) {
                yield from $this->findThemes($item);
            }
        }
    }
}
