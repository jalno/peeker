<?php
namespace packages\peeker\actions;

use packages\base\{IO\File, Log};
use packages\peeker\{Action, IAction, IActionFile, IPreloadedMd5};

class RemoveFile extends Action implements IActionFile {

	protected $file;

	public function __construct(File $file) {
		$this->file = $file;
	}

	public function getFile(): File {
		return $this->file;
	}

	public function hasConflict(IAction $other): bool {
		return (!$other instanceof static and $other instanceof IActionFile and $other->getFile()->getPath() == $this->file->getPath());
	}

	public function isValid(): bool {
		if ($this->file instanceof IPreloadedMd5) {
			$this->file->resetMd5();
		}
		return $this->file->exists();
	}

	public function do(): void {
		$log = Log::getInstance();
		$log->info("delete ", $this->file->getPath());
		$this->file->delete();
	}

	public function serialize() {
		return serialize(array(
			$this->file,
			$this->reason,
		));
	}

	public function unserialize($serialized) {
		$data = unserialize($serialized);
		$this->file = $data[0];
		$this->reason = $data[1];
	}
}
