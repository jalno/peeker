<?php

namespace packages\peeker\scanners\wordpress;

use packages\base\Exception;

class PluginException extends Exception
{
    public const EMPTY_PLUGIN = 101;
    public const DAMAGED_PLUGIN = 102;
    public const ORIGINAL_NOTFOUND = 103;

    protected $version;

    public function __construct(string $message, int $code, ?string $version = null)
    {
        parent::__construct($message, $code);
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }
}
