<?php

namespace packages\peeker\actions\wordpress;

use packages\base\IO\Directory;
use packages\peeker\{actions\RemoveDirectory};

class ResetWPRocketCache extends RemoveDirectory
{
    public function __construct(Directory $wordpress)
    {
        $wpRocket = $wordpress->directory('wp-content/cache/wp-rocket');
        $target = null;
        if ($wpRocket->exists()) {
            $siteDirectories = $wpRocket->directories(false);
            if ($siteDirectories) {
                $target = $siteDirectories[0];
            }
        }
        if (!$target) {
            $target = $wpRocket->directory('non-existing-directory');
        }
        parent::__construct($target);
    }
}
