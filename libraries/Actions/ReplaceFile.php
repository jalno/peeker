<?php

namespace packages\peeker\Actions;

use packages\base\IO\File;
use packages\base\Log;
use packages\peeker\Action;
use packages\peeker\IAction;
use packages\peeker\IActionFile;
use packages\peeker\IO\Directory\IPreloadedDirectory;
use packages\peeker\IO\IPreloadedMd5;
use packages\peeker\Scanner;

class ReplaceFile extends Action implements IActionFile
{
    protected $source;
    protected $destination;

    public function __construct(File $destination, File $source)
    {
        $this->destination = $destination;
        $this->source = $source;
    }

    public function getFile(): File
    {
        return $this->destination;
    }

    public function getSource(): File
    {
        return $this->source;
    }

    public function hasConflict(IAction $other): bool
    {
        if ($other instanceof static and $other->destination->getPath() == $this->destination->getPath() and $other->source->getPath() == $this->source->getPath()) {
            return false;
        }

        return $other instanceof IActionFile and ($other->getFile()->getPath() == $this->destination->getPath() or $other->getFile()->getPath() == $this->source->getPath());
    }

    public function isValid(): bool
    {
        if ($this->source instanceof IPreloadedMd5) {
            $this->source->resetMd5();
        }

        return $this->source->exists();
    }

    public function do(): void
    {
        $log = Log::getInstance();
        $log->info('copy', $this->source->getPath(), 'to', $this->destination->getPath());
        if (!$this->destination->exists()) {
            if (!$this->destination->getDirectory()->exists()) {
                $this->destination->getDirectory()->make();
            }
            if ($this->scanner instanceof Scanner) {
                $home = $this->scanner->getHome();
                if ($home instanceof IPreloadedDirectory) {
                    $path = $this->destination->getRelativePath($home);
                    $this->destination = $home->createPreloadedFile($path);
                }
            }
        }
        $this->destination->copyFrom($this->source);
        $log->reply('Success');
    }

    public function serialize()
    {
        return serialize([
            $this->destination,
            $this->source,
            $this->reason,
        ]);
    }

    public function unserialize($serialized)
    {
        $data = unserialize($serialized);
        $this->destination = $data[0];
        $this->source = $data[1];
        $this->reason = $data[2];
    }
}
