<?php

namespace packages\peeker\Scanners;

use packages\base\IO\Directory;
use packages\base\IO\File;
use packages\base\Log;
use packages\peeker\Action;
use packages\peeker\ActionConflictException;
use packages\peeker\ActionManager;
use packages\peeker\Actions\CleanFile;
use packages\peeker\Actions\RemoveFile;
use packages\peeker\Actions\ReplaceFile;
use packages\peeker\Scanner;

class DirectoryChangesScanner extends Scanner
{
    public const DIFF_CREATED = 'CREATED';
    public const DIFF_MODIFIED = 'MODIFIED';
    public const DIFF_REMOVED = 'REMOVED';
    public const DIFF_SAME = 'SAME';

    protected array $extensions = [];
    protected array $ignorePaths = [];
    protected bool $infacted = false;

    public function __construct(ActionManager $actions, Directory $home, protected Directory $original)
    {
        parent::__construct($actions, $home);
    }

    public function setExtensions(array $extensions): static
    {
        $this->extensions = $extensions;

        return $this;
    }

    public function setIgnorePaths(array $paths): static
    {
        $this->ignorePaths = array_map(fn (string $p) => ltrim($p, '/'), $paths);

        return $this;
    }

    public function isInfacted(): bool
    {
        return $this->infacted;
    }

    public function prepare(): void
    {
    }

    public function scan(): void
    {
        $log = Log::getInstance();

        $log->debug('Calculate diffrence of directories and add actions accordingly');
        $this->infacted = false;
        foreach ($this->diff() as $path => $change) {
            $input = $this->home->file($path);
            if (self::DIFF_SAME == $change) {
                $action = new CleanFile($input);
                $action->setReason('same-original-file');
            } elseif (self::DIFF_CREATED == $change) {
                $this->infacted = true;
                $action = new RemoveFile($input);
                $action->setReason('not-in-original');
            } else {
                $this->infacted = true;
                $action = new ReplaceFile($input, $this->original->file($path));
                $action->setReason(match ($change) {
                    self::DIFF_MODIFIED => 'modified-based-on-original',
                    self::DIFF_REMOVED => 'removed-based-on-original',
                });
            }
            try {
                $log->debug('Adding ', Action::getName($action), 'for', $path, ' reason:'.$action->getReason());
                $this->actions->add($action);
            } catch (ActionConflictException $conflict) {
                $old = $conflict->getOldAction();
                $log->reply()->warn('Confilit to '.Action::getName($old).', overriding');
                $this->actions->delete($old);
                $this->actions->add($action);
            }
        }
    }

    /**
     * @return \Generator<string,string>
     */
    private function diff(): \Generator
    {
        $originalFiles = $this->directoryMd5Map($this->original);
        $inputFiles = $this->directoryMd5Map($this->home);

        foreach ($originalFiles as $path => $originalMd5) {
            if (!isset($inputFiles[$path])) {
                yield $path => self::DIFF_REMOVED;
            } elseif ($inputFiles[$path] != $originalMd5) {
                yield $path => self::DIFF_MODIFIED;
            } else {
                yield $path => self::DIFF_SAME;
            }
        }
        foreach (array_keys($inputFiles) as $path) {
            if (!isset($originalFiles[$path])) {
                yield $path => self::DIFF_CREATED;
            }
        }
    }

    /**
     * @return array<string,string>
     */
    private function directoryMd5Map(Directory $root): array
    {
        $result = [];

        foreach ($root->files(true) as $file) {
            /**
             * @var File $file
             */
            $path = $file->getRelativePath($root);

            if ($this->shouldIgnoreFile($path)) {
                continue;
            }
            $md5 = method_exists($file, 'md5') ? $file->md5() : md5($file->read());
            $result[$path] = $md5;
        }

        return $result;
    }

    private function shouldIgnoreFile(string $path): bool
    {
        $name = basename($path);
        if ($this->extensions) {
            $dot = strrpos($name, '.');
            if (null !== $dot) {
                $extension = substr($name, $dot + 1);
                if (!in_array($extension, $this->extensions)) {
                    return true;
                }
            }
        }
        foreach ($this->ignorePaths as $ignorePath) {
            if (str_starts_with(ltrim($path, '/'), $ignorePath)) {
                return true;
            }
        }

        return false;
    }
}
