<?php

namespace packages\peeker\Actions;

use packages\base\IO\{File};
use packages\peeker\Action;
use packages\peeker\IAction;
use packages\peeker\IActionFile;

class CleanFile extends Action implements IActionFile
{
    protected $file;

    public function __construct(File $file)
    {
        $this->file = $file;
    }

    public function getFile(): File
    {
        return $this->file;
    }

    public function hasConflict(IAction $other): bool
    {
        return !($other instanceof static) and $other instanceof IActionFile and $other->getFile()->getPath() == $this->file->getPath();
    }

    public function isValid(): bool
    {
        return true;
    }

    public function do(): void
    {
    }

    public function __serialize()
    {
        return [
            $this->file,
            $this->reason,
        ];
    }

    public function __unserialize(array $data)
    {
        $this->file = $data[0];
        $this->reason = $data[1];
    }
}
