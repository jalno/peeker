<?php
namespace packages\peeker;

abstract class Action implements IAction {
	protected $scanner;
	protected $reason;

	public function setScanner(?IScanner $scanner): void {
		$this->scanner = $scanner;
	}
	public function getScanner(): ?IScanner {
		return $this->scanner;
	}
	public function setReason(string $reason): Action {
		$this->reason = $reason;
		return $this;
	}
	public function getReason(): ?string {
		return $this->reason;
	}
}
