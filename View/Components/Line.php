<?php

namespace Voyager\Console\View\Components;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * A titled line: the coloured badge every status message is made of.
 */
class Line extends Component
{
    /**
     * Background/foreground per style name.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    protected array $colors = [
        'info'    => ['blue', 'white'],
        'warn'    => ['yellow', 'black'],
        'error'   => ['red', 'white'],
        'success' => ['green', 'white'],
    ];

    public function render(string $style, string $string, int $verbosity = OutputInterface::VERBOSITY_NORMAL): void
    {
        [$background, $foreground] = $this->colors[$style] ?? $this->colors['info'];

        $this->draw(sprintf(
            '<div class="mx-2 mb-1"><span class="px-1 bg-%s text-%s uppercase">%s</span><span class="ml-1">%s</span></div>',
            $background,
            $foreground,
            $this->escape($style),
            $this->escape($string),
        ), $verbosity);
    }
}
