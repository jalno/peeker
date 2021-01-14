<?php
namespace packages\peeker\events\wordpress;

use packages\base\Event;
use packages\peeker\actions\wordpress\SiteURLRepair as Action;

class SiteURLRepair extends Event {
	/**
	 * @var Action
	 */
	protected $action;

	public function __construct(Action $action) {
		$this->action = $action;
	}

	public function getAction(): Action {
		return $this->action;
	}

}
