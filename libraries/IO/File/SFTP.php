<?php
namespace packages\peeker\IO\File;

use packages\base\IO\File\SFTP as BaseSFTP;
use packages\peeker\IO\{Directory, IPreloadedMd5};

class SFTP extends BaseSFTP implements IPreloadedMd5 {
	public $parent;
	public $preloadedMd5;

	public function write(string $data): bool {
		$result = parent::write($data);
		if ($result) {
			$this->preloadedMd5 = md5($data);
		} else {
			$this->preloadedMd5 = null;
		}
		return $result;
	}

	public function isPreloadMd5(): bool {
		return $this->preloadedMd5 !== null;
	}
	
	public function preloadMd5(): void {
		$line = $this->getDriver()->getSSH()->execute("md5sum " . $this->getPath());
		if (!preg_match("/^([a-z0-9]{32})\s+(.+)$/", $line, $matches)) {
			throw new Exception("invalid line");
		}
		$this->preloadedMd5 = $matches[1];
	}

	public function resetMd5(): void {
		$this->preloadedMd5 = null;
	}

	public function md5(): string {
		if (!$this->preloadedMd5) {
			$this->preloadMd5();
		}
		return $this->preloadedMd5;
	}
	public function exists(): bool {
		return ($this->preloadedMd5 or parent::exists());
	}

	public function getDirectory(): Directory\SFTP {
		if ($this->parent) {
			return $this->parent;
		}
		return parent::getDirectory();
	}

	public function delete(): void {
		parent::delete();
		if ($this->parent and $this->parent->isPreloadItems()) {
			for ($x = 0, $l = count($this->parent->preloadItems); $x < $l; $x++) {
				if ($this->parent->preloadItems[$x] == $this) {
					array_splice($this->parent->preloadItems, $x, 1);
					break;
				}
			}
		}
    }
}