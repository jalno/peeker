<?php
namespace packages\peeker\actions\repairs;

use packages\base\{IO\File, Log};
use packages\peeker\{IAction, IActionFile, actions\Repair, IO\IPreloadedMd5};

class InjectedFirstlinePHPRepair extends Repair implements IActionFile {
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
		$log->info("Repair injected php in first line {$this->file->getPath()}");
		$content = $this->file->read();
		if ($this->mode == "default") {
			$content = preg_replace('/^<\?php\s{200,}.+eval.+\?><\?php/i', '<?php', $content);
		} elseif ($this->mode == "md5") {
			$content = preg_replace('/^<\?php.+md5.+\?><\?php/i', '<?php', $content);
		} elseif ($this->mode == "second-line") {
			$content = preg_replace('/\<\?php\s+.+\$_REQUEST\[md5\([\s\S]+function_exists:\s+true.+\s+.+\?><\?php/', '<?php', $content);
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
