<?php
namespace packages\peeker;

interface IScanner {
	public function prepare(): void;
	public function scan(): void;
}
