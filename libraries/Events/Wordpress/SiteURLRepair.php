<?php

namespace packages\peeker\Events\Wordpress;

use packages\base\Event;
use packages\peeker\Actions\Wordpress\SiteURLRepair as Action;

class SiteURLRepair extends Event
{
    /**
     * @var Action
     */
    protected $action;

    public function __construct(Action $action)
    {
        $this->action = $action;
    }

    public function getAction(): Action
    {
        return $this->action;
    }
}
