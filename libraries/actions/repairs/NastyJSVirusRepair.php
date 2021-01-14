<?php
namespace packages\peeker\actions\repairs;

use packages\base\{IO\File, Log};
use packages\peeker\{IAction, IActionFile, actions\Repair, IO\IPreloadedMd5};

class NastyJSVirusRepair extends Repair implements IActionFile {
	protected $file;
	protected $mode;
	protected $md5;

	public function __construct(File $file, string $mode) {
		$this->file = $file;
		$this->mode = $mode;
		$this->md5 = $file->md5();
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
		return ($this->file->exists() and $this->file->md5() == $this->md5);
	}

	public function do(): void {
		$log = Log::getInstance();
		$log->info("Repair nasty js virues {$this->file->getPath()}");
		$content = $this->file->read();
		if ($this->mode == "in-php") {
			$content = preg_replace("/<script.*>\s*Element.prototype.appendAfter =.+\)\)\[0\].appendChild\(elem\);}\)\(\);<\/script>/", "", $content);
		} elseif ($this->mode == "in-js") {
			$content = preg_replace("/^Element.prototype.appendAfter =.+\)\)\[0\].appendChild\(elem\);}\)\(\);/", "", $content);
		}
		$this->file->write($content);
	}

	public function serialize() {
		return serialize(array(
			$this->file,
			$this->mode,
			$this->md5,
		));
	}

	public function unserialize($serialized) {
		$data = unserialize($serialized);
		$this->file = $data[0];
		$this->mode = $data[1];
		$this->md5 = $data[2];
	}

}
