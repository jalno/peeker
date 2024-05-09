<?php

namespace packages\peeker\IO\Directory;

use packages\base\Exception;
use packages\base\IO as baseIO;
use packages\base\IO\Directory\SFTP as BaseDirectorySFTP;
use packages\base\IO\File\SFTP as BaseFileSFTP;
use packages\base\Log;
use packages\peeker\IO\File;
use packages\peeker\IO\IPreloadedMd5;

class SFTP extends BaseDirectorySFTP implements IPreloadedDirectory, IPreloadedMd5
{
    public $parent;

    /**
     * @var array<File\SFTP|self>|null
     */
    public ?array $preloadedItems = null;

    protected bool $preloadedMd5 = false;

    public function isPreloadItems(): bool
    {
        return null !== $this->preloadedItems;
    }

    public function preloadItems(): void
    {
        $log = Log::getInstance();
        
        $root = $this;
        $rootPath = rtrim($root->getPath(), '/').'/';
        
        $log->debug("Preloading directories");

        $lines = $this->getDriver()->getSSH()->execute("find -P {$rootPath} -type d");
        $lines = explode("\n", $lines);

        $driver = $root->getDriver();
        $root->preloadedItems = [];
        $root->preloadedMd5 = false;
    
        for ($x = 1, $l = count($lines); $x < $l - 1; $x++)
        {
            $path = $lines[$x];

            while(!str_starts_with($path, rtrim($root->getPath(), '/') . '/')) {
                $root = $root->parent;
            }

            $sub = new self($path);
            $sub->setDriver($driver);
            $sub->preloadedItems = [];
            $sub->preloadedMd5 = true;
            $sub->parent = $root;

            $root->preloadedItems[] = $sub;
            $root = $sub;
        }
        $log->reply($l - 2);

        $log->debug("Preloading files alongside their md5");
        $lines = $this->getDriver()->getSSH()->execute("find -P {$rootPath} -type f -exec md5sum {} \;");
        $lines = explode("\n", $lines);

        $rootPathLength = strlen($rootPath);
        for ($x = 0, $l = count($lines); $x < $l - 1; $x++)
        {
            $line = $lines[$x];
            $md5 = substr($line, 0, 32);
            $path = substr($line, 32 + 2);
        
            $dirpath = substr($path, $rootPathLength);
            $dirpath = explode("/", $dirpath);
            array_pop($dirpath);

            $dir = $this;
            foreach ($dirpath as $dirname) {
                foreach ($dir->preloadedItems as $i) {
                    if ($i instanceof self and $i->basename == $dirname) {
                        $dir = $i;
                        break;
                    }
                }
            }

            $file = new File\SFTP($path);
            $file->setDriver($driver);
            $file->preloadedMd5 = $md5;
            $file->parent = $dir;
            $dir->preloadedItems[] = $file;
        }
        $log->reply($l - 1);

        $this->preloadedMd5 = true;
    }

    public function resetItems(): void
    {
        $this->preloadedItems = null;
    }

    public function isPreloadMd5(): bool
    {
        return $this->preloadedMd5;
    }

    public function preloadMd5(): void
    {
        if (!$this->isPreloadItems()) {
            $this->preloadItems();
        }
    }

    public function resetMd5(): void
    {
        if (!$this->preloadedMd5) {
            return;
        }
        $this->preloadedMd5 = false;
        if (!$this->isPreloadItems()) {
            return;
        }
        $files = $this->files(true);
        foreach ($files as $file) {
            $file->preloadedMd5 = null;
        }
    }

    public function files(bool $recursively = false): array
    {
        if (!$this->isPreloadItems()) {
            return parent::files($recursively);
        }
    
        $items = [];
        foreach ($this->preloadedItems as $item) {
            if ($item instanceof BaseFileSFTP) {
                $items[] = $item;
            } elseif ($item instanceof BaseDirectorySFTP and $recursively) {
                array_push($items, ...$item->files(true));
            }
        }

        return $items;
    }

    public function directories(bool $recursively = true): array
    {
        if (!$this->isPreloadItems()) {
            return parent::directories($recursively);
        }

        $items = [];
        foreach ($this->preloadedItems as $item) {
            if (!$item instanceof BaseDirectorySFTP) {
                continue;
            }
            $items[] = $item;
            if ($recursively) {
                array_push($items, ...$item->directories(true));
            }
        }

        return $items;
    }

    public function items(bool $recursively = true): array
    {
        if (!$this->isPreloadItems()) {
            return parent::items($recursively);
        }
    
        $items = [];
        foreach ($this->preloadedItems as $item) {
            $items[] = $item;
            if ($item instanceof BaseDirectorySFTP and $recursively) {
                array_push($items, ...$item->items(true));
            }
        }

        return $items;
    }

    public function exists(): bool
    {
        return $this->isPreloadItems() or parent::exists();
    }

    public function file(string $name): BaseFileSFTP
    {
        if ($this->isPreloadItems()) {
            $firstSlash = strpos($name, '/');
            $firstPart = false !== $firstSlash ? substr($name, 0, $firstSlash) : $name;
            foreach ($this->preloadedItems as $item) {
                if ($item->basename == $firstPart) {
                    return false === $firstSlash ? $item : $item->file(substr($name, $firstSlash + 1));
                }
            }
        }
        $file = new File\SFTP($this->getPath().'/'.$name);
        $file->setDriver($this->getDriver());

        return $file;
    }

    public function directory(string $name): BaseDirectorySFTP
    {
        if ($this->isPreloadItems()) {
            $firstSlash = strpos($name, '/');
            $firstPart = false !== $firstSlash ? substr($name, 0, $firstSlash) : $name;
            foreach ($this->preloadedItems as $item) {
                if ($item->basename == $firstPart) {
                    return false === $firstSlash ? $item : $item->directory(substr($name, $firstSlash + 1));
                }
            }
        }
        $directory = new SFTP($this->getPath().'/'.$name);
        $directory->setDriver($this->getDriver());

        return $directory;
    }

    public function getDirectory(): BaseDirectorySFTP
    {
        if ($this->parent) {
            return $this->parent;
        }
        $directory = new SFTP($this->directory);
        $directory->setDriver($this->getDriver());

        return $directory;
    }

    public function delete(): void
    {
        parent::delete();
        if ($this->isPreloadItems()) {
            for ($x = 0, $l = count($this->preloadedItems); $x < $l; ++$x) {
                $this->preloadedItems[$x]->parent = null;
            }
            $this->preloadedItems = null;
        }
        if ($this->parent and $this->parent->isPreloadItems()) {
            for ($x = 0, $l = count($this->parent->preloadedItems); $x < $l; ++$x) {
                if ($this->parent->preloadedItems[$x] == $this) {
                    array_splice($this->parent->preloadedItems, $x, 1);
                    break;
                }
            }
        }
    }

    public function createPreloadedFile(string $name): baseIO\File
    {
        if (null === $this->preloadedItems) {
            $this->preloadedItems = [];
        }

        $firstSlash = strpos($name, '/');
        $firstPart = false !== $firstSlash ? substr($name, 0, $firstSlash) : $name;
        $found = null;
        foreach ($this->preloadedItems as $item) {
            if ($item->basename == $firstPart) {
                $found = $item;
                break;
            }
        }
        if (!$found) {
            $found = false === $firstSlash ? new File\SFTP($this->getPath().'/'.$firstPart) : new SFTP($this->getPath().'/'.$firstPart);
            $found->setDriver($this->getDriver());
            $this->preloadedItems[] = $found;
        }

        return false === $firstSlash ? $found : $found->createPreloadedFile(substr($name, $firstSlash + 1));
    }

    public function createPreloadedDirectory(string $path): IPreloadedDirectory
    {
        if (null === $this->preloadedItems) {
            $this->preloadedItems = [];
        }

        $firstSlash = strpos($path, '/');
        $firstPart = false !== $firstSlash ? substr($path, 0, $firstSlash) : $path;
        $found = null;
        foreach ($this->preloadedItems as $item) {
            if ($item->basename == $firstPart) {
                $found = $item;
                break;
            }
        }
        if (!$found) {
            $found = new SFTP($this->getPath().'/'.$firstPart);
            $found->setDriver($this->getDriver());
            $this->preloadedItems[] = $found;
        }

        return false === $firstSlash ? $found : $found->createPreloadedDirectory(substr($path, $firstSlash + 1));
    }
}
