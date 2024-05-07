<?php

namespace packages\peeker\IO\Directory;

use packages\base\IO as baseIO;

interface IPreloadedDirectory
{
    public function isPreloadItems(): bool;

    public function preloadItems(): void;

    public function resetItems(): void;

    public function createPreloadedFile(string $path): baseIO\File;

    public function createPreloadedDirectory(string $path): IPreloadedDirectory;
}
