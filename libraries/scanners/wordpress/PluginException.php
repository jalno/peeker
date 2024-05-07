<?php

namespace packages\peeker\scanners\wordpress;

use packages\base\Exception;

class PluginException extends Exception
{
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
