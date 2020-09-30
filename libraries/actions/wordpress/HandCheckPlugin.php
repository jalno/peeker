<?php
namespace packages\peeker\actions\wordpress;

use packages\base\{IO\Directory, Log, Exception};
use packages\peeker\{Action, IAction, IActionInteractive, IActionDirectory, IInterface, IO\IPreloadedMd5, scanners};

class HandCheckPlugin extends Action implements IActionInteractive, IActionDirectory {

	protected $directory;
	protected $version;
	protected $interface;
	protected $answer;

	public function __construct(Directory $directory, ?string $version) {
		$this->directory = $directory;
		$this->version = $version;
	}

	public function getDirectory(): Directory {
		return $this->directory;
	}

	public function hasConflict(IAction $other): bool {
		return (!$other instanceof static and $other instanceof IActionDirectory and $other->getDirectory()->getPath() == $this->directory->getPath());
	}

	public function isValid(): bool {
		if ($this->answer == "I") {
			return false;
		}
		return $this->directory->exists();
	}

	public function setInterface(IInterface $interface): void {
		$this->interface = $interface;
	}

	public function getInterface(): ?IInterface {
		return $this->interface;
	}

	public function hasQuestions(): bool {
		return $this->answer === null;
	}

	public function askQuestions(): void {
		$answers = array(
			"I" => "Ignore",
			"R" => "Retry",
			"D" => "Delete",
		);
		$this->interface->askQuestion("Please check wordpress plugin {$this->directory->getPath()}" . ($this->version ? "@" . $this->version : "") . ($this->reason ? ", Reason: {$this->reason}" : ""), $answers, function($answer) {
			if ($answer == "R") {
				try {
					scanners\wordpress\PluginScanner::checkPlugin($this->directory);
					$this->answer = "I";
				} catch (scanners\wordpress\PluginException $e) {
					$log = Log::getInstance();
					$log->error($e->getMessage());
					switch ($e->getCode()) {
						case 101:
							$this->answer = "D";
							break;
					}
				}
				return;
			}
			$this->answer = $answer;
		});
	}

	public function do(): void {
		$log = Log::getInstance();
		if (!$this->answer) {
			$log->fatal("not answered yet");
			throw new Exception("not ready to do anything");
		}
		if ($this->answer == "D") {
			$log->info("delete ", $this->directory->getPath());
			$this->directory->delete();
		} else {
			$log->debug("No op");
		}
	}

	public function serialize() {
		return serialize(array(
			$this->directory,
			$this->version,
			$this->reason,
		));
	}

	public function unserialize($serialized) {
		$data = unserialize($serialized);
		$this->directory = $data[0];
		$this->version = $data[1];
		$this->reason = $data[2];
	}
}