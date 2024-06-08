<?php

namespace packages\peeker\Scanners\Wordpress;

use packages\base\IO\Directory;
use packages\base\Log;
use packages\peeker\Actions\RemoveDirectory;
use packages\peeker\Actions\Wordpress\HandCheckPlugin;
use packages\peeker\Actions\Wordpress\ResetWPRocketCache;
use packages\peeker\Scanner;
use packages\peeker\Scanners\DirectoryChangesScanner;
use packages\peeker\WordpressDownloader;
use packages\peeker\WordpressScript;

class PluginScanner extends Scanner
{
    public static function checkPlugin(Directory $plugin): Directory
    {
        if ($plugin->isEmpty()) {
            throw new PluginException('Empty directory', PluginException::EMPTY_PLUGIN);
        }
        $info = WordpressScript::getPluginInfo($plugin);
        if (empty($info)) {
            throw new PluginException('Plugin damaged', PluginException::DAMAGED_PLUGIN);
        }
        if (!isset($info['version'])) {
            $info['version'] = null;
        }
        try {
            $original = WordpressDownloader::getInstance()->plugin($plugin->basename, $info['version']);

            return $original;
        } catch (\Exception $e) {
            throw new PluginException('Plugin Original Not found', PluginException::ORIGINAL_NOTFOUND, $info['version']);
        }
    }

    /**
     * @param array<string,array{directory:Directory,original:Directory\Local}}>
     */
    protected array $plugins = [];

    public function prepare(): void
    {
        $plugins = $this->findPlugins();
        foreach ($plugins as $plugin) {
            $this->preparePlugin($plugin);
        }
    }

    public function scan(): void
    {
        if (!$this->plugins) {
            return;
        }
        $log = Log::getInstance();

        foreach ($this->plugins as $plugin) {
            $log->info("Compare plugin with it's original:", $plugin['directory']->getRelativePath($this->home), 'and', $plugin['original']->getPath());
            $this->scanPlugin($plugin['directory'], $plugin['original']);
        }
    }

    protected function scanPlugin(Directory $input, Directory\Local $original)
    {
        $scanner = new DirectoryChangesScanner($this->actions, $input, $original);
        $scanner->setExtensions(['php', 'js']);
        $scanner->scan();
        if ($scanner->isInfacted()) {
            $this->actions->add((new ResetWPRocketCache($this->home))->setReason('infacted-wordpress-plugin'));
        }
    }

    protected function preparePlugin(Directory $plugin): void
    {
        $log = Log::getInstance();
        try {
            $path = $plugin->getRelativePath($this->home);
            $log->info('Check '.$path);
            $original = self::checkPlugin($plugin);
            $this->plugins[$plugin->basename] = [
                'directory' => $plugin,
                'original' => $original,
            ];
            $log->reply('done');
        } catch (PluginException $e) {
            $log->reply()->error($e->getMessage());
            switch ($e->getCode()) {
                case PluginException::EMPTY_PLUGIN:
                    $log->reply('removing it');
                    $this->actions->add((new RemoveDirectory($plugin))->setReason('empty-plugin'));
                    break;
                case PluginException::DAMAGED_PLUGIN:
                    $this->actions->add((new RemoveDirectory($plugin))->setReason('plugin-damaged'));
                    break;
                case PluginException::ORIGINAL_NOTFOUND:
                    $this->actions->add((new HandCheckPlugin($plugin, $e->getVersion()))->setReason('plugin-notfound'));
                    break;
            }
        }
    }

    protected function findPlugins(?Directory $directory = null): \Iterator
    {
        if (null == $directory) {
            $directory = $this->home;
        }
        $directories = $directory->directories(false);
        foreach ($directories as $item) {
            if ('plugins' == $item->basename and 'wp-content' == $directory->basename) {
                yield from $item->directories(false);
            } elseif (!in_array($item->basename, ['.quarantine', '.tmb', '.well-known', 'cgi-bin', 'wp-admin', 'wp-includes', 'busting'])) {
                yield from $this->findPlugins($item);
            }
        }
    }
}
