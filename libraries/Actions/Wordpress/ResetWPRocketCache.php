<?php

namespace packages\peeker\Actions\Wordpress;

use packages\base\IO\Directory;
use packages\peeker\{Actions\RemoveDirectory};

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
