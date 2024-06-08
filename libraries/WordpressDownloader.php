<?php

namespace packages\peeker;

use packages\base\HTTP\Client;
use packages\base\HTTP\ResponseException;
use packages\base\IO\Directory;
use packages\base\IO\File;
use packages\base\Log;
use packages\base\Packages;

class WordpressDownloader
{
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private Directory\Local $cacheRoot;

    public function __construct()
    {
        $this->cacheRoot = Packages::package('peeker')->getStorage('private')->getRoot();
    }

    public function download(string $url): File\TMP
    {
        $log = Log::getInstance();

        $log->info("Downloading {$url}");
        $file = new File\TMP();
        try {
            $response = (new Client())->get($url, [
                'save_as' => $file,
                'headers' => [
                    'user-agent' => 'WordPress/6.5.2; https://www.jeyserver.com/',
                ],
            ]);
        } catch (ResponseException $e) {
            $log->reply()->error('Http code:'.$e->getResponse()->getStatusCode());
            throw $e;
        }

        return $file;
    }

    public function plugin(string $name, ?string $version = null): Directory\Local
    {
        $log = Log::getInstance();

        if (str_ends_with($name, '-master')) {
            $name = substr($name, 0, -strlen('-master'));
        }

        $cache = $this->getPluginCacheDirectory($name, $version);
        if ($cache->exists()) {
            $log->info('Using cached version of plugin:', $name, ', version:', $version ?? 'latest');

            return $cache;
        }

        $log->info('download plugin:', $name, ', version:', $version ?? 'latest');
        $fileName = ($version ? "{$name}.{$version}" : $name);
        $zip = $this->download("https://downloads.wordpress.org/plugin/{$fileName}.zip");
        $log->reply('Done');

        $log->info('Extract it');
        $extracted = $this->extract($zip);
        $content = $extracted->directory($name);

        if (!$content->copyTo($cache)) {
            throw new \Exception('Cannot rename '.$content->getPath().' to '.$cache->getPath());
        }
        $log->reply('Done');

        return $cache;
    }

    public function theme(string $name, string $version): Directory\Local
    {
        $log = Log::getInstance();

        if (str_ends_with($name, '-master')) {
            $name = substr($name, 0, -strlen('-master'));
        }

        $cache = $this->getThemeCacheDirectory($name, $version);
        if ($cache->exists()) {
            $log->info('Using cached version of theme:', $name, ', version:', $version);

            return $cache;
        }

        $log->info('download theme:', $name, ', version:', $version);
        $zip = $this->download("https://downloads.wordpress.org/theme/{$name}.{$version}.zip");
        $log->reply('Done');

        $log->info('Extract it');
        $extracted = $this->extract($zip);
        $content = $extracted->directory($name);

        if (!$content->copyTo($cache)) {
            throw new \Exception('Cannot rename '.$content->getPath().' to '.$cache->getPath());
        }
        $log->reply('Done');

        return $cache;
    }

    public function version(string $version): Directory\Local
    {
        $log = Log::getInstance();

        $cache = $this->getVersionCacheDirectory($version);
        if ($cache->exists()) {
            $log->info('Using cached version of wordpress:', $version);

            return $cache;
        }

        $log->info('download wordpress:', $version);
        $zip = $this->download("https://wordpress.org/wordpress-{$version}.zip");
        $log->reply('Done');

        $log->info('Extract it');
        $extracted = $this->extract($zip);
        $content = $extracted->directory('wordpress');

        if (!$content->copyTo($cache)) {
            throw new \Exception('Cannot rename '.$content->getPath().' to '.$cache->getPath());
        }
        $log->reply('Done');

        return $cache;
    }

    private function getPluginCacheDirectory(string $name, ?string $version = null): Directory\Local
    {
        return $this->cacheRoot->directory('plugins/'.$name.'/'.($version ?? 'latest'));
    }

    private function getThemeCacheDirectory(string $name, ?string $version = null): Directory\Local
    {
        return $this->cacheRoot->directory('themes/'.$name.'/'.($version ?? 'latest'));
    }

    private function getVersionCacheDirectory(string $version): Directory\Local
    {
        return $this->cacheRoot->directory('wordpress-versions/'.$version);
    }

    private function extract(File\Local $zipFile): Directory\Tmp
    {
        $target = new Directory\TMP();
        $zip = new \ZipArchive();
        $open = $zip->open($zipFile->getPath());
        if (true !== $open) {
            throw new \Exception('Cannot open zip file: '.$open);
        }
        try {
            $target->make(true);
            if (!$zip->extractTo($target->getPath())) {
                throw new \Exception('Cannot extract zip file: '.$target->getPath());
            }
        } finally {
            $zip->close();
        }

        return $target;
    }
}
