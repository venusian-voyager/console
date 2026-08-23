<?php

namespace Voyager\Console\Contracts;

interface NewLineAware
{
    /**
     * How many trailing newlines were written.
     *
     * @return int
     */
    public function newLinesWritten(): int;

    /**
     * Whether a newline has already been written.
     *
     * @return bool
     *
     * @deprecated use newLinesWritten
     */
    public function newLineWritten(): bool;
}
