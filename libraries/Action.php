<?php

namespace packages\peeker;

abstract class Action implements IAction
{
    public static function getName(string|IAction $action): string
    {
        if (!is_string($action)) {
            $action = get_class($action);
        }
        $namespace = __NAMESPACE__.'\\actions\\';
        if (str_starts_with(strtolower($action), $namespace)) {
            $action = substr($action, strlen($namespace));
        }

        return $action;
    }

    protected ?IScanner $scanner = null;
    protected ?string $reason = null;

    public function setScanner(?IScanner $scanner): void
    {
        $this->scanner = $scanner;
    }

    public function getScanner(): ?IScanner
    {
        return $this->scanner;
    }

    public function setReason(string $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }
}
