<?php

namespace packages\peeker;

use packages\base\IO\File;

interface IInterface
{
    public function askQuestion(string $question, ?array $answers, \Closure $callback): void;

    public function showFile(File $file): void;
}
