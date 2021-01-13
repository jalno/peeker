<?php
namespace packages\peeker\actions\repairs;

use packages\base\{IO\File, Log};
use packages\peeker\{IAction, IActionFile, IO\IPreloadedMd5, actions\Repair};

class InjectedHostingCloudRacing extends Repair implements IActionFile {
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
		$log->info("Repair hostingcloud.racing virues {$this->file->getPath()}");
		$content = $this->file->read();
		if ($this->mode == "default") {
			$content = preg_replace("/<script.*src=\".*hostingcloud.*\".*><\/script>/i", "", $content);
			$content = preg_replace("/<script>.*Client.Anonymous.*(.*)<\/script>/i", "", $content);
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
