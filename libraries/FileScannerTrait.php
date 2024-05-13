<?php

namespace packages\peeker;

use packages\base\IO\Directory;
use packages\base\IO\File;

/**
 * @property ActionManager $actions
 */
trait FileScannerTrait
{
    /**
     * @return \Generator<File>
     */
    protected function getFiles(Directory $directory, ?array $extensions = null): \Generator
    {
        foreach ($directory->files(true) as $item) {
            if ($extensions and !in_array($item->getExtension(), $extensions)) {
                continue;
            }
            yield $item;
        }
    }

    protected function getFilesWithNoAction(Directory $directory, ?array $extensions = null): \Generator
    {
        foreach ($directory->items(false) as $item) {
            if ($item instanceof File) {
                if ($extensions and !in_array($item->getExtension(), $extensions)) {
                    continue;
                }

                $found = false;
                foreach ($this->actions->getActionsForFile($item) as $a) {
                    $found = true;
                    break;
                }
                if ($found) {
                    continue;
                }

                yield $item;
            } elseif ($item instanceof Directory) {
                $found = false;
                foreach ($this->actions->getActionsForDirectory($item) as $a) {
                    $found = true;
                    break;
                }
                if ($found) {
                    continue;
                }
                yield from $this->getFilesWithNoAction($item, $extensions);
            }
        }
    }
}
