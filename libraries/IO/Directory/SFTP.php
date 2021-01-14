<?php
namespace packages\peeker\IO\Directory;

use packages\base\{IO as baseIO, IO\Directory\SFTP as BaseDirectorySFTP, IO\File\SFTP as BaseFileSFTP, Exception};
use packages\peeker\IO\{IPreloadedMd5, File};

class SFTP extends BaseDirectorySFTP implements IPreloadedDirectory, IPreloadedMd5 {
	public $parent;
	public $preloadedItems;
	protected $preloadedMd5 = false;

	public function isPreloadItems(): bool {
		return $this->preloadedItems !== null;
	}

	public function preloadItems(): void {
		$root = rtrim($this->getPath(), "/"). "/";
		$lines = $this->getDriver()->getSSH()->execute("find -P {$root} \( -type f -or -type d \) -printf \"%y\\t%p\\n\"");
	
		$lastPath = $root;
		$lastDirectory = $this;

		foreach (explode("\n", $lines) as $line) {
			if (!$line) {
				continue;
			}
			if (!preg_match("/^(f|d)\s+(.+)$/", $line, $matches)) {
				throw new Exception("invalid line");
			}
			$path = $matches[2];
			if ($path == $root) {
				continue;
			}
			if ($matches[1] == 'd') {
				$path .= "/";
				$item = new SFTP($path);
				$item->preloadedItems = [];
			} else {
				$item = new File\SFTP($path);
			}
			$item->setDriver($this->getDriver());

			while (substr($path, 0, strlen($lastPath)) != $lastPath) {
				$lastPath = $lastDirectory->parent->getPath() . "/";
				$lastDirectory = $lastDirectory->parent;
			}
			$item->parent = $lastDirectory;
			if ($item->parent->preloadedItems === null) {
				$item->parent->preloadedItems = [];
			}
			$item->parent->preloadedItems[] = $item;
			if ($matches[1] == 'd') {
				$lastPath = $path;
				$lastDirectory = $item;
			}
		}
	}

	public function resetItems(): void {
		$this->preloadedItems = null;
	}

	public function isPreloadMd5(): bool {
		return $this->preloadedMd5;
	}
	
	public function preloadMd5(): void {
		if (!$this->isPreloadItems()) {
			$this->preloadItems();
		}
		$files = $this->files(true);
		for ($x = 0, $l = count($files); $x < $l; $x += 100) {
			$part = array_slice($files, $x, 100);
			$paths = array_map(function($file) {
				return "\"" . $file->getPath() . "\"";
			}, $part);
			echo "do\n";
			$lines = $this->getDriver()->getSSH()->execute("md5sum " . implode(" ", $paths));
			echo "done\n";
			$y = 0;
			foreach (explode("\n", $lines) as $line) {
				if (!$line) {
					continue;
				}
				if (!preg_match("/^([a-z0-9]{32})\s+(.+)$/", $line, $matches)) {
					throw new Exception("invalid line: {$line}");
				}
				$files[$x + $y]->preloadedMd5 = $matches[1];
				$y++;
			}
		}
		$this->preloadedMd5 = true;
	}
	public function resetMd5(): void {
		if (!$this->preloadedMd5) {
			return;
		}
		$this->preloadedMd5 = false;
		if (!$this->isPreloadItems()) {
			return;
		}
		$files = $this->files(true);
		foreach ($files as $file) {
			$file->preloadedMd5 = null;
		}
	}

	public function files(bool $recursively = false): array {
		if (!$this->isPreloadItems()) {
			$driver = $this->getDriver();
			$scanner = function($dir) use($recursively, $driver, &$scanner){
				$items = [];
				$handle = $driver->opendir($dir);
				while (($basename = readdir($handle)) !== false) {
					if($basename != '.' and $basename != '..'){
						$item = $dir.'/'.$basename;
						if($driver->is_file($item)){
							$file = new File\SFTP($item);
							$file->setDriver($driver);
							$items[] = $file;
						}elseif($recursively and $driver->is_dir($item)){
							$items = array_merge($items, $scanner($item));
						}
					}
				}
				return $items;
			};
			return $scanner($this->getPath());
		}
		$items = [];
		foreach ($this->preloadedItems as $item) {
			if ($item instanceof BaseFileSFTP) {
				$items[] = $item;
			} elseif ($item instanceof BaseDirectorySFTP and $recursively) {
				$items = array_merge($items, $item->files(true));
			}
		}
		return $items;
	}

	public function directories(bool $recursively = true): array {
		if (!$this->isPreloadItems()) {
			$driver = $this->getDriver();
			$scanner = function($dir) use($recursively, $driver, &$scanner){
				$items = [];
				$handle = $driver->opendir($dir);
				while (($basename = readdir($handle)) !== false) {
					if($basename != '.' and $basename != '..'){
						$item = $dir.'/'.$basename;
						if($driver->is_dir($item)){
							$directory = new SFTP($item);
							$directory->setDriver($driver);
							$items[] = $directory;
							if($recursively){
								$items = array_merge($items, $scanner($item));
							}
						}
					}
				}
				return $items;
			};
			return $scanner($this->getPath());
		}
		$items = [];
		foreach ($this->preloadedItems as $item) {
			if ($item instanceof BaseDirectorySFTP) {
				$items[] = $item;
				if ($recursively) {
					$items = array_merge($items, $item->directories(true));
				}
			}
		}
		return $items;
	}

	public function items(bool $recursively = true): array {
		if (!$this->isPreloadItems()) {
			$driver = $this->getDriver();
			$scanner = function($dir) use($recursively, $driver, &$scanner){
				$items = [];
				$handle = $driver->opendir($dir);
				while (($basename = readdir($handle)) !== false) {
					if($basename != '.' and $basename != '..'){
						$item = $dir.'/'.$basename;
						if($driver->is_file($item)){
							$file = new File\SFTP($item);
							$file->setDriver($driver);
							$items[] = $file;
						}elseif($driver->is_dir($item)){
							$directory = new SFTP($item);
							$directory->setDriver($driver);
							$items[] = $directory;
							if($recursively){
								$items = array_merge($items, $scanner($item));
							}
						}
					}
				}
				return $items;
			};
			return $scanner($this->getPath());
		}
		$items = [];
		foreach ($this->preloadedItems as $item) {
			$items[] = $item;
			if ($item instanceof BaseDirectorySFTP and $recursively) {
				$items = array_merge($items, $item->directories(true));
			}
		}
		return $items;
	}

	public function exists(): bool {
		return ($this->isPreloadItems() or parent::exists());
	}

	public function file(string $name): BaseFileSFTP {
		if ($this->isPreloadItems()) {
			$firstSlash = strpos($name, "/");
			$firstPart = $firstSlash !== false ? substr($name, 0, $firstSlash) : $name;
			foreach ($this->preloadedItems as $item) {
				if ($item->basename == $firstPart) {
					return $firstSlash === false ? $item : $item->file(substr($name, $firstSlash + 1));
				}
			}
		}
		$file = new File\SFTP($this->getPath( ). '/' . $name);
		$file->setDriver($this->getDriver());
		return $file;
	}

	public function directory(string $name): BaseDirectorySFTP {
		if ($this->isPreloadItems()) {
			$firstSlash = strpos($name, "/");
			$firstPart = $firstSlash !== false ? substr($name, 0, $firstSlash) : $name;
			foreach ($this->preloadedItems as $item) {
				if ($item->basename == $firstPart) {
					return $firstSlash === false ? $item : $item->directory(substr($name, $firstSlash + 1));
				}
			}
		}
		$directory = new SFTP($this->getPath() . '/' . $name);
		$directory->setDriver($this->getDriver());
		return $directory;
	}

	public function getDirectory(): BaseDirectorySFTP {
		if ($this->parent) {
			return $this->parent;
		}
		$directory = new SFTP($this->directory);
		$directory->setDriver($this->getDriver());
		return $directory;
	}


    public function delete(): void {
		parent::delete();
		if ($this->isPreloadItems()) {
			for ($x = 0, $l = count($this->preloadedItems); $x < $l; $x++) {
				$this->preloadedItems[$x]->parent = null;
			}
			$this->preloadedItems = null;
		}
		if ($this->parent and $this->parent->isPreloadItems()) {
			for ($x = 0, $l = count($this->parent->preloadedItems); $x < $l; $x++) {
				if ($this->parent->preloadedItems[$x] == $this) {
					array_splice($this->parent->preloadedItems, $x, 1);
					break;
				}
			}
		}
    }

	
	public function createPreloadedFile(string $name): baseIO\File {
		if ($this->preloadedItems === null) {
			$this->preloadedItems = [];
		}
		
		$firstSlash = strpos($name, "/");
		$firstPart = $firstSlash !== false ? substr($name, 0, $firstSlash) : $name;
		$found = null;
		foreach ($this->preloadedItems as $item) {
			if ($item->basename == $firstPart) {
				$found = $item;
				break;
			}
		}
		if (!$found) {
			$found = $firstSlash === false ? new File\SFTP($this->getPath() . "/" . $firstPart) : new SFTP($this->getPath() . "/" . $firstPart);
			$found->setDriver($this->getDriver());
			$this->preloadedItems[] = $found;
		}
		return $firstSlash === false ? $found : $found->createPreloadedFile(substr($name, $firstSlash + 1));
	}

	public function createPreloadedDirectory(string $path): IPreloadedDirectory {
		if ($this->preloadedItems === null) {
			$this->preloadedItems = [];
		}
		
		$firstSlash = strpos($path, "/");
		$firstPart = $firstSlash !== false ? substr($path, 0, $firstSlash) : $path;
		$found = null;
		foreach ($this->preloadedItems as $item) {
			if ($item->basename == $firstPart) {
				$found = $item;
				break;
			}
		}
		if (!$found) {
			$found = new SFTP($this->getPath() . "/" . $firstPart);
			$found->setDriver($this->getDriver());
			$this->preloadedItems[] = $found;
		}
		return $firstSlash === false ? $found : $found->createPreloadedDirectory(substr($path, $firstSlash + 1));
	}
}
