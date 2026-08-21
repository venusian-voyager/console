<?php

namespace Voyager\Console;

interface CommandMutex
{
    /**
     * Attempt to obtain a command mutex for the given command.
     *
     * @param  \Voyager\Console\Command  $command
     * @return bool
     */
    public function create($command): bool;

    /**
     * Determine if a command mutex exists for the given command.
     *
     * @param  \Voyager\Console\Command  $command
     * @return bool
     */
    public function exists($command): bool;

    /**
     * Release the mutex for the given command.
     *
     * @param  \Voyager\Console\Command  $command
     * @return bool
     */
    public function forget($command): bool;
}
