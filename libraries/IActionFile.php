<?php

namespace packages\peeker;

use packages\base\IO\File;

interface IActionFile extends IAction
{
    public function getFile(): File;
}
