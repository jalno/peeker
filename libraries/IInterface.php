<?php

namespace packages\peeker;

use packages\base\IO\File;

interface IInterface
{
    public function askQuestion(string $question, ?array $answers = null, \Closure $callback): void;

    public function showFile(File $file): void;
}
