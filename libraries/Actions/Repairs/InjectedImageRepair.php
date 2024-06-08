<?php

namespace packages\peeker\Actions\Repairs;

use packages\base\IO\File;
use packages\base\Log;
use packages\peeker\Actions\Repair;
use packages\peeker\IAction;
use packages\peeker\IActionFile;
use packages\peeker\IO\IPreloadedMd5;

class InjectedImageRepair extends Repair implements IActionFile
{
    protected $file;
    protected $md5;

    public function __construct(File $file)
    {
        $this->file = $file;
        $this->md5 = $file->md5();
    }

    public function getFile(): File
    {
        return $this->file;
    }

    public function hasConflict(IAction $other): bool
    {
        return !$other instanceof static and $other instanceof IActionFile and $other->getFile()->getPath() == $this->file->getPath();
    }

    public function isValid(): bool
    {
        if ($this->file instanceof IPreloadedMd5) {
            $this->file->resetMd5();
        }

        return $this->file->exists() and $this->file->md5() == $this->md5;
    }

    public function do(): void
    {
        $log = Log::getInstance();
        $log->info("Repair injected JS {$this->file->getPath()}");
        $content = $this->file->read();
        $content = preg_replace("/^<img.+onerror=\"eval.+(<\?php)/", '$1', $content);
        $this->file->write($content);
    }

    public function serialize()
    {
        return serialize([
            $this->file,
            $this->md5,
        ]);
    }

    public function unserialize($serialized)
    {
        $data = unserialize($serialized);
        $this->file = $data[0];
        $this->md5 = $data[1];
    }
}
