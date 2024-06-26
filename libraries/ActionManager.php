<?php

namespace packages\peeker;

use packages\base\IO\Directory;
use packages\base\IO\File;
use packages\base\{Log};
use packages\peeker\actions\CleanFile;

class ActionManager
{
    protected $actions = [];
    protected $interface;

    public function __construct(IInterface $interface)
    {
        $this->interface = $interface;
    }

    public function reset(): void
    {
        $this->actions = [];
    }

    public function add(Action $action): void
    {
        $log = Log::getInstance();
        if (in_array($action, $this->actions, true)) {
            return;
        }
        foreach ($this->actions as $item) {
            if ($item->hasConflict($action)) {
                $log->debug('Action conflict between '.get_class($action).' (new) and '.get_class($item).' (old)');
                throw new ActionConflictException($this, $item, $action, 'Action Conflict');
            }
        }
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT) as $item) {
            if ($item['object'] instanceof IScanner) {
                $action->setScanner($item['object']);
                break;
            }
        }
        $this->actions[] = $action;
    }

    public function getActions(): array
    {
        return $this->actions;
    }

    public function delete(IAction $action): void
    {
        for ($x = 0, $l = count($this->actions); $x < $l; ++$x) {
            if ($this->actions[$x] === $action) {
                array_splice($this->actions, $x, 1);
                break;
            }
        }
    }

    public function doActions(?bool $onlyInteractive = null): void
    {
        $log = Log::getInstance();
        for ($x = 0, $l = count($this->actions); $x < $l; ++$x) {
            $action = $this->actions[$x];
            if (null === $onlyInteractive or (($action instanceof IActionInteractive and $action->hasQuestions()) === $onlyInteractive)) {
                if (!$action instanceof CleanFile) {
                    $log->info('Action: '.get_class($action));
                    $this->doAction($action);
                }
                array_splice($this->actions, $x, 1);
                --$x;
                --$l;
            }
        }
    }

    public function doNonInteractiveActions(): void
    {
        $this->doActions(false);
    }

    public function doInteractiveActions(): void
    {
        $this->doActions(true);
    }

    public function doAction(IAction $action): void
    {
        $log = Log::getInstance();
        $valid = $action->isValid();
        $log->debug('Valid:', $valid);
        if (!$valid) {
            return;
        }
        if ($action instanceof IActionInteractive) {
            $action->setInterface($this->interface);
            while ($action->hasQuestions()) {
                $action->askQuestions();
            }
            $valid = $action->isValid();
            $log->debug('Valid:', false);
            if (!$valid) {
                return;
            }
        }
        $action->do();
    }

    public function hasActions(?bool $onlyInteractive = null): bool
    {
        foreach ($this->actions as $action) {
            if (null === $onlyInteractive or $action instanceof IActionInteractive == $onlyInteractive) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return \Generator<null,IActionFile>
     */
    public function getActionFiles(): \Generator
    {
        foreach ($this->actions as $action) {
            if ($action instanceof IActionFile) {
                yield $action;
            }
        }
    }

    /**
     * @return \Generator<null,IActionDirectory>
     */
    public function getActionDirectories(): \Generator
    {
        foreach ($this->actions as $action) {
            if ($action instanceof IActionDirectory) {
                yield $action;
            }
        }
    }

    /**
     * @return \Generator<null,IActionFile>
     */
    public function getActionsForFile(File $file): \Generator
    {
        foreach ($this->getActionFiles() as $action) {
            if ($action->getFile()->getPath() == $file->getPath()) {
                yield $action;
            }
        }
    }

    /**
     * @return \Generator<null,IActionDirectory>
     */
    public function getActionsForDirectory(Directory $directory): \Generator
    {
        foreach ($this->getActionDirectories() as $action) {
            if ($action->getDirectory()->getPath() == $directory->getPath()) {
                yield $action;
            }
        }
    }

    public function isFileMarkAsClean(File $file): bool
    {
        foreach ($this->getActionsForFile($file) as $action) {
            if ($action instanceof CleanFile) {
                return true;
            }
        }

        return false;
    }
}
