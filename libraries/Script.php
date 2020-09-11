<?php
namespace packages\peeker;
use packages\base\IO\directory\local as directory;

class Script {
	/**
	 * @var directory
	 */
	protected $home;
	public function __construct(directory $home){
		$this->home = $home;
	}

	/**
	 * Get the value of home
	 *
	 * @return  directory
	 */ 
	public function getHome(): directory {
		return $this->home;
	}

	/**
	 * Set the value of home
	 *
	 * @param  directory  $home
	 * @return  void
	 */ 
	public function setHome(directory $home) {
		$this->home = $home;
	}

	/**
	 * Specify data which should be serialized to JSON
	 * 
	 * @return string
	 */
	public function jsonSerialize() {
		return $this->home->getPath();
	}
}