<?php

namespace packages\peeker\IO;

interface IPreloadedMd5
{
    public function isPreloadMd5(): bool;

    public function preloadMd5(): void;

    public function resetMd5(): void;
}
