<?php

namespace Voyager\Console\Events;

use Voyager\Console\Application;

class ComputerStarting
{
    /**
     * Create a new event instance.
     *
     * @param  \Voyager\Console\Application  $computer  The Computer application instance.
     */
    public function __construct(
        public Application $computer,
    ) {
    }
}
