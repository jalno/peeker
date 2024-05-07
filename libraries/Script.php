<?php

namespace packages\peeker;

use packages\base\IO\Directory;

class Script
{
    /**
     * @var Directory
     */
    protected $home;

    public function __construct(Directory $home)
    {
        $this->home = $home;
    }

    /**
     * Get the value of home.
     */
    public function getHome(): Directory
    {
        return $this->home;
    }

    /**
     * Set the value of home.
     *
     * @return void
     */
    public function setHome(Directory $home)
    {
        $this->home = $home;
    }

    /**
     * Specify data which should be serialized to JSON.
     *
     * @return string
     */
    public function jsonSerialize()
    {
        throw new \packages\base\Exception('TODO');

        return $this->home->getPath();
    }
}
