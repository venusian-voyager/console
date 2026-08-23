<?php

namespace Voyager\Console\Scheduling;

interface EventMutex
{
    /**
     * Attempt to obtain an event mutex for the given event.
     *
     * @param  \Voyager\Console\Scheduling\Event  $event
     * @return bool
     */
    public function create(Event $event): bool;

    /**
     * Determine if an event mutex exists for the given event.
     *
     * @param  \Voyager\Console\Scheduling\Event  $event
     * @return bool
     */
    public function exists(Event $event): bool;

    /**
     * Clear the event mutex for the given event.
     *
     * @param  \Voyager\Console\Scheduling\Event  $event
     * @return void
     */
    public function forget(Event $event): void;
}
