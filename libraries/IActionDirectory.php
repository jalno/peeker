<?php
namespace packages\peeker;

use packages\base\IO\Directory;

interface IActionDirectory extends IAction {
	public function getDirectory(): Directory;
}
