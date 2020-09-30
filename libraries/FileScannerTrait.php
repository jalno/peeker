<?php
namespace packages\peeker;

use packages\base\IO\{Directory, File};

trait FileScannerTrait {
	
	protected function getFiles(Directory $directory, ?array $extensions = null): \Iterator {
		foreach ($directory->items(false) as $item) {
			if ($item instanceof File) {
				if (!$extensions or in_array($item->getExtension(), $extensions)) {
					yield $item;
				}
			} elseif ($item instanceof Directory) {
				yield from $this->getFiles($item, $extensions);
			}
		}
	}
}
