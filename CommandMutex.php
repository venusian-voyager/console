<?php

namespace Voyager\Console;

interface CommandMutex
{
    /**
     * Attempt to obtain a command mutex for the given command.
     *
     * @param \Voyager\Console\Command $command
     * @return bool
     */
    public function create(Command $command): bool;

    /**
     * Determine if a command mutex exists for the given command.
     *
     * @param \Voyager\Console\Command $command
     * @return bool
     */
    public function exists(Command $command): bool;

    /**
     * Release the mutex for the given command.
     *
     * @param \Voyager\Console\Command $command
     * @return bool
     */
    public function forget(Command $command): bool;
}
