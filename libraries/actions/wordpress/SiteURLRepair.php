<?php

namespace packages\peeker\actions\wordpress;

use packages\base\Exception;
use packages\base\InputValidationException;
use packages\base\Log;
use packages\base\Validator;
use packages\peeker\actions\Repair;
use packages\peeker\events;
use packages\peeker\IAction;
use packages\peeker\IActionDatabase;
use packages\peeker\IActionInteractive;
use packages\peeker\IInterface;
use packages\peeker\WordpressScript;

class SiteURLRepair extends Repair implements IActionDatabase, IActionInteractive
{
    protected $script;
    protected $answer;
    protected $interface;

    public function __construct(WordpressScript $script)
    {
        $this->script = $script;
    }

    public function getScript(): WordpressScript
    {
        return $this->script;
    }

    public function hasConflict(IAction $other): bool
    {
        return false;
    }

    public function isValid(): bool
    {
        return true;
    }

    public function setInterface(IInterface $interface): void
    {
        $this->interface = $interface;
    }

    public function getInterface(): ?IInterface
    {
        return $this->interface;
    }

    public function hasQuestions(): bool
    {
        if (!$this->answer) {
            (new events\wordpress\SiteURLRepair($this))->trigger();
        }

        return !$this->answer;
    }

    public function setAnswer(?string $answer): void
    {
        $this->answer = $answer;
    }

    public function askQuestions(): void
    {
        $this->interface->askQuestion('In seems the site url have been changed, please provide a correct one', null, function ($answer) {
            try {
                $answer = (new Validator\URLValidator())->validate('siteurl', [], $answer);
                $this->answer = $answer ? rtrim($answer, '/').'/' : '';
            } catch (InputValidationException $e) {
            }
        });
    }

    public function do(): void
    {
        $log = Log::getInstance();
        if (!$this->answer) {
            $log->fatal('not answered yet');
            throw new Exception('not ready to do anything');
        }
        $this->script->setOption('siteurl', $this->answer);
        $this->script->setOption('home', $this->answer);
    }

    public function serialize()
    {
        return serialize([
            $this->script,
            $this->answer,
        ]);
    }

    public function unserialize($serialized)
    {
        $data = unserialize($serialized);
        $this->script = $data[0];
        $this->answer = $data[1];
    }
}
