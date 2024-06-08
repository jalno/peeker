<?php

namespace packages\peeker\Actions\Wordpress;

use packages\base\Exception;
use packages\base\IO\Directory;
use packages\base\Log;
use packages\peeker\Action;
use packages\peeker\IAction;
use packages\peeker\IActionDirectory;
use packages\peeker\IActionInteractive;
use packages\peeker\IInterface;
use packages\peeker\Scanners;

class HandCheckPlugin extends Action implements IActionInteractive, IActionDirectory
{
    protected ?IInterface $interface = null;
    protected ?string $answer = null;

    public function __construct(protected Directory $directory, protected ?string $version)
    {
        $this->directory = $directory;
        $this->version = $version;
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
        if ('I' == $this->answer) {
            return false;
        }

        return $this->directory->exists();
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
        return null === $this->answer;
    }

    public function askQuestions(): void
    {
        $answers = [
            'I' => 'Ignore',
            'R' => 'Retry',
            'D' => 'Delete',
        ];
        $this->interface->askQuestion("Please check wordpress plugin {$this->directory->getPath()}".($this->version ? '@'.$this->version : '').($this->reason ? ", Reason: {$this->reason}" : ''), $answers, function ($answer) {
            if ('R' == $answer) {
                try {
                    Scanners\Wordpress\PluginScanner::checkPlugin($this->directory);
                    $this->answer = 'I';
                } catch (Scanners\Wordpress\PluginException $e) {
                    $log = Log::getInstance();
                    $log->error($e->getMessage());
                    switch ($e->getCode()) {
                        case 101:
                            $this->answer = 'D';
                            break;
                    }
                }

                return;
            }
            $this->answer = $answer;
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
            $log->info('delete ', $this->directory->getPath());
            $this->directory->delete();
        } else {
            $log->debug('No op');
        }
    }

    public function serialize()
    {
        return serialize([
            $this->directory,
            $this->version,
            $this->reason,
        ]);
    }

    public function unserialize($serialized)
    {
        $data = unserialize($serialized);
        $this->directory = $data[0];
        $this->version = $data[1];
        $this->reason = $data[2];
    }
}
