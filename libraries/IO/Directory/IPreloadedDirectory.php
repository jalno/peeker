<?php
namespace packages\peeker\IO\Directory;

interface IPreloadedDirectory {
	public function isPreloadItems(): bool;
	public function preloadItems(): void;
	public function resetItems(): void;
}
