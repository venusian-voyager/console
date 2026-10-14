<?php

namespace Voyager\Console\View\Components;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * A full-width banner: what confirmToProceed() shows before a destructive command runs in production.
 */
class Alert extends Component
{
    /**
     * Renders the component.
     */
    public function render(string $string, int $verbosity = OutputInterface::VERBOSITY_NORMAL): void
    {
        $this->draw(sprintf(
            '<div class="w-full mx-2 py-1 mt-1 bg-yellow text-black text-center uppercase">%s</div>',
            $this->escape($string),
        ), $verbosity);
    }
}
