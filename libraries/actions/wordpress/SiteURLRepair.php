<?php
namespace packages\peeker\actions\wordpress;

use packages\base\{Log, Exception};
use packages\peeker\{IActionDatabase, IActionInteractive, IAction, IInterface, WordpressScript, actions\Repair};

class SiteURLRepair extends Repair implements IActionDatabase, IActionInteractive {

	protected $script;
	protected $answer;
	protected $interface;

	public function __construct(WordpressScript $script) {
		$this->script = $script;
	}

	public function getScript(): WordpressScript {
		return $this->script;
	}

	public function hasConflict(IAction $other): bool {
		return false;
	}

	public function isValid(): bool {
		return true;
	}

	public function setInterface(IInterface $interface): void {
		$this->interface = $interface;
	}

	public function getInterface(): ?IInterface {
		return $this->interface;
	}

	public function hasQuestions(): bool {
		return !$this->answer;
	}

	public function askQuestions(): void {
		$this->interface->askQuestion("In seems the site url have been changed, please provide a correct one", null, function($answer) {
			$this->answer = $answer ? rtrim($answer, "/") . "/" : "";
		});
	}

	public function do(): void {
		$log = Log::getInstance();
		if (!$this->answer) {
			$log->fatal("not answered yet");
			throw new Exception("not ready to do anything");
		}
		$this->script->setOption("siteurl", $this->answer);
		$this->script->setOption("home", $this->answer);
	}

	public function serialize() {
		return serialize(array(
			$this->script,
			$this->answer,
		));
	}

	public function unserialize($serialized) {
		$data = unserialize($serialized);
		$this->script = $data[0];
		$this->answer = $data[1];
	}
}
