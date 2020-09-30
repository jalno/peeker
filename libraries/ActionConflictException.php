<?php
namespace packages\peeker;

use packages\base\Exception;

class ActionConflictException extends Exception {
	protected $actions;
	protected $old;
	protected $new;

	public function __construct(ActionManager $actions, IAction $old, IAction $new, string $message) {
		parent::__construct($message);
		$this->actions = $actions;
		$this->old = $old;
		$this->new = $new;
	}

	public function getActionManager(): ActionManager {
		return $this->actions;
	}
	
	public function getOldAction(): IAction {
		return $this->old;
	}
	
	public function getNewAction(): IAction {
		return $this->new;
	}
	
	public function applyOld(): void {
		// Intentionally left empty
	}

	public function applyNew(): void {
		$this->actions->delete($this->old);
		$this->actions->add($this->new);
	}
}
