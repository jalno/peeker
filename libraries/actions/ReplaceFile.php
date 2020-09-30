<?php
namespace packages\peeker\actions;

use packages\base\{IO\File, Log};
use packages\peeker\{Action, IAction, IActionFile, IO\IPreloadedMd5};

class ReplaceFile extends Action implements IActionFile {

	protected $source;
	protected $destination;

	public function __construct(File $destination, File $source) {
		$this->destination = $destination;
		$this->source = $source;
	}

	public function getFile(): File {
		return $this->destination;
	}

	public function getSource(): File {
		return $this->source;
	}

	public function hasConflict(IAction $other): bool {
		if ($other instanceof static and $other->destination->getPath() == $this->destination->getPath() and $other->source->getPath() == $this->source->getPath()) {
			return false;
		}
		return ($other instanceof IActionFile and ($other->getFile()->getPath() == $this->destination->getPath() or $other->getFile()->getPath() == $this->source->getPath()));
	}

	public function isValid(): bool {
		if ($this->source instanceof IPreloadedMd5) {
			$this->source->resetMd5();
		}
		return $this->source->exists();
	}

	public function do(): void {
		$log = Log::getInstance();
		$log->info("copy", $this->source->getPath(), "to", $this->destination->getPath());
		$this->source->copyTo($this->destination);
		$log->reply("Success");
	}

	public function serialize() {
		return serialize(array(
			$this->destination,
			$this->source,
			$this->reason,
		));
	}

	public function unserialize($serialized) {
		$data = unserialize($serialized);
		$this->destination = $data[0];
		$this->source = $data[1];
		$this->reason = $data[2];
	}
}
