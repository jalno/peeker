<?php

namespace packages\peeker\actions;

use packages\base\Exception;
use packages\base\IO\File;
use packages\base\Log;
use packages\peeker\Action;
use packages\peeker\IAction;
use packages\peeker\IActionFile;
use packages\peeker\IActionInteractive;
use packages\peeker\IInterface;
use packages\peeker\IO\IPreloadedMd5;

class HandCheckFile extends Action implements IActionInteractive, IActionFile
{
    protected $file;
    protected $original;
    protected $md5;
    protected $interface;
    protected $answer;

    public function __construct(File $file)
    {
        $this->file = $file;
        $this->md5 = $file->md5();
    }

    public function getFile(): File
    {
        return $this->file;
    }

    public function setOriginalFile(File $file): HandCheckFile
    {
        $this->original = $file;

        return $this;
    }

    public function hasConflict(IAction $other): bool
    {
        return !$other instanceof static and $other instanceof IActionFile and $other->getFile()->getPath() == $this->file->getPath();
    }

    public function isValid(): bool
    {
        if ('OK' == $this->answer) {
            return false;
        }
        if ($this->file instanceof IPreloadedMd5) {
            $this->file->resetMd5();
        }

        return $this->file->exists() and $this->file->md5() == $this->md5;
    }

    public function setInterface(IInterface $interface): void
    {
        $this->interface = $interface;
    }

    public function getInterface(): ?IInterface
    {
        return $this->interface;
    }

    public function hasQuestions(): bool
    {
        if (null !== $this->answer) {
            return false;
        }
        $history = (new HandCheckFile\MD5())
            ->where('md5', $this->md5)
            ->getOne();
        if ($history) {
            $this->answer = $history->toAnswer();
        }

        return null === $this->answer;
    }

    public function askQuestions(): void
    {
        $answers = [
            'OK' => 'OK',
            'D' => 'Delete',
            'S' => 'Show',
        ];
        if ($this->original) {
            $answers['R'] = 'Replace';
        }
        $this->interface->askQuestion("Please check {$this->file->getPath()}".($this->reason ? ", Reason: {$this->reason}" : ''), $answers, function ($answer) {
            if ('S' == $answer) {
                $this->interface->showFile($this->file);

                return;
            }
            $this->answer = $answer;

            if ($this->file instanceof IPreloadedMd5) {
                $this->file->resetMd5();
            }

            $md5 = new HandCheckFile\MD5();
            $md5->md5 = $this->file->md5();
            $md5->reason = $this->reason;
            $md5->fromAnswer($this->answer);
            $md5->save();
        });
    }

    public function do(): void
    {
        $log = Log::getInstance();
        if (!$this->answer) {
            $log->fatal('not answered yet');
            throw new Exception('not ready to do anything');
        }
        if ('D' == $this->answer) {
            $log->info('delete ', $this->file->getPath());
            $this->file->delete();
        } elseif ('R' == $this->answer) {
            $log->info('replace ', $this->file->getPath(), 'with', $this->original->getPath());
            $this->original->copyTo($this->file);
        } else {
            $log->debug('No op');
        }
    }

    public function serialize()
    {
        return serialize([
            $this->file,
            $this->md5,
            $this->reason,
        ]);
    }

    public function unserialize($serialized)
    {
        $data = unserialize($serialized);
        $this->file = $data[0];
        $this->md5 = $data[1];
        $this->reason = $data[2];
    }
}
