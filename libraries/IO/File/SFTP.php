<?php

namespace packages\peeker\IO\File;

use packages\base\Exception;
use packages\base\IO as BaseIO;
use packages\base\IO\File\SFTP as BaseSFTP;
use packages\peeker\IO\Directory;
use packages\peeker\IO\IPreloadedMd5;

class SFTP extends BaseSFTP implements IPreloadedMd5
{
    public $parent;
    public $preloadedMd5;

    public function write(string $data): bool
    {
        $result = parent::write($data);
        if ($result) {
            $this->preloadedMd5 = md5($data);
        } else {
            $this->preloadedMd5 = null;
        }

        return $result;
    }

    public function copyTo(BaseIO\File $dest): bool
    {
        $result = parent::copyTo($dest);
        if ($result and $dest instanceof IPreloadedMd5) {
            $dest->preloadedMd5 = $this->preloadedMd5;
            if ($dest->parent instanceof Directory\IPreloadedDirectory) {
                $cache = $dest->parent->createPreloadedFile($dest->basename);
                $cache->preloadMd5 = $this->preloadedMd5;
            }
        }

        return $result;
    }

    public function copyFrom(BaseIO\File $source): bool
    {
        $result = parent::copyFrom($source);
        if ($result) {
            if ($source instanceof IPreloadedMd5) {
                $this->preloadedMd5 = $source->preloadedMd5;
            } elseif ($source instanceof BaseIO\File\Local) {
                $this->preloadedMd5 = $source->md5();
            } else {
                $this->preloadedMd5 = null;
            }
            if ($this->parent instanceof Directory\IPreloadedDirectory) {
                $cache = $this->parent->createPreloadedFile($this->basename);
                $cache->preloadMd5 = $this->preloadedMd5;
            }
        }

        return $result;
    }

    public function isPreloadMd5(): bool
    {
        return null !== $this->preloadedMd5;
    }

    public function preloadMd5(): void
    {
        $line = $this->getDriver()->getSSH()->execute('md5sum "'.$this->getPath().'"');
        if (!preg_match("/^([a-z0-9]{32})\s+(.+)$/", $line, $matches)) {
            throw new Exception('invalid line');
        }
        $this->preloadedMd5 = $matches[1];
    }

    public function resetMd5(): void
    {
        $this->preloadedMd5 = null;
    }

    public function md5(): string
    {
        if (!$this->preloadedMd5) {
            $this->preloadMd5();
        }

        return $this->preloadedMd5;
    }

    public function exists(): bool
    {
        return $this->preloadedMd5 or parent::exists();
    }

    public function getDirectory(): Directory\SFTP
    {
        if ($this->parent) {
            return $this->parent;
        }
        $directory = new Directory\SFTP($this->directory);
        $directory->setDriver($this->getDriver());

        return $directory;
    }

    public function delete(): void
    {
        parent::delete();
        if ($this->parent and $this->parent->isPreloadItems()) {
            for ($x = 0, $l = count($this->parent->preloadedItems); $x < $l; ++$x) {
                if ($this->parent->preloadedItems[$x] == $this) {
                    array_splice($this->parent->preloadedItems, $x, 1);
                    break;
                }
            }
        }
    }
}
