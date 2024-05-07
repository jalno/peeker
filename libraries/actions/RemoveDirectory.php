<?php

namespace packages\peeker\actions;

use packages\base\IO\Directory;
use packages\base\Log;
use packages\peeker\Action;
use packages\peeker\IAction;
use packages\peeker\IActionDirectory;

class RemoveDirectory extends Action implements IActionDirectory
{
    protected $directory;

    public function __construct(Directory $directory)
    {
        $this->directory = $directory;
    }

    public function getDirectory(): Directory
    {
        return $this->directory;
    }

    public function hasConflict(IAction $other): bool
    {
        return !$other instanceof static and $other instanceof IActionDirectory and $other->getDirectory()->getPath() == $this->directory->getPath();
    }

    public function isValid(): bool
    {
        return $this->directory->exists();
    }

    public function do(): void
    {
        $log = Log::getInstance();
        $log->info('delete ', $this->directory->getPath());
        $this->directory->delete();
        $log->reply('Success');
    }

    public function serialize()
    {
        return serialize([
            $this->directory,
            $this->reason,
        ]);
    }

    public function unserialize($serialized)
    {
        $data = unserialize($serialized);
        $this->directory = $data[0];
        $this->reason = $data[1];
    }
}
