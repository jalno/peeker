<?php
namespace packages\peeker;

use packages\base\{Exception, IO\Directory};

class PluginException extends Exception {
	protected $plugin;
	protected $info;

	public function __construct(Directory $plugin, string $message, ?array $info = null) {
		parent::__construct($message);
		$this->plugin = $plugin;
		$this->info = $info;
	}
	public function getPlugin(): Directory {
		return $this->plugin;
	}
	public function getInfo(): ?array {
		return $this->info;
	}
}