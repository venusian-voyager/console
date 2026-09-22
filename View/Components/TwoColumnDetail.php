<?php

namespace Voyager\Console\View\Components;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Left text, dots, right text — the shape every "x .... DONE" line uses.
 */
class TwoColumnDetail extends Component
{
    public function render(string $first, ?string $second = null, int $verbosity = OutputInterface::VERBOSITY_NORMAL): void
    {
        $this->draw(sprintf(
            '<div class="flex mx-2 max-w-150"><span>%s</span><span class="flex-1 content-repeat-[.] text-gray mx-1"></span><span>%s</span></div>',
            $this->escape($first),
            $this->escape((string) $second),
        ), $verbosity);
    }
}
