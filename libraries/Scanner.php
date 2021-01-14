<?php
namespace packages\peeker;

use packages\base\IO\Directory;

abstract class Scanner implements IScanner {
	protected $home;
	protected $actions;

	public function __construct(ActionManager $actions, Directory $home) {
		$this->actions = $actions;
		$this->home = $home;
	}

	public function getHome(): Directory {
		return $this->home;
	}

	public function prepare(): void {
	}
}
