<?php

namespace Voyager\Console\View\Components;

use Throwable;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Run a piece of work and report how it went on one line.
 */
class Task extends Component
{
    public function render(string $description, ?callable $task = null, int $verbosity = OutputInterface::VERBOSITY_NORMAL): void
    {
        try {
            $succeeded = is_null($task) || $task() !== false;
        } catch (Throwable $e) {
            // the line still has to be drawn before the throwable leaves
            $this->outcome($description, false, $verbosity);

            throw $e;
        }

        $this->outcome($description, $succeeded, $verbosity);
    }

    /**
     * A two-column line whose right side is ours, not the caller's, so it carries colour.
     */
    private function outcome(string $description, bool $succeeded, int $verbosity): void
    {
        $this->draw(sprintf(
            '<div class="flex mx-2 max-w-150"><span>%s</span><span class="flex-1 content-repeat-[.] text-gray mx-1"></span><span class="font-bold text-%s">%s</span></div>',
            $this->escape($description),
            $succeeded ? 'green' : 'red',
            $succeeded ? 'DONE' : 'FAIL',
        ), $verbosity);
    }
}
