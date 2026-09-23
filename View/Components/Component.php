<?php

namespace Voyager\Console\View\Components;

use Symfony\Component\Console\Output\OutputInterface;
use Voyager\Console\OutputStyle;

use function Termwind\render;
use function Termwind\renderUsing;

/**
 * A single piece of console output. Termwind does the drawing; a component only
 * decides what html to hand it.
 */
abstract class Component
{
    public function __construct(
        protected readonly OutputStyle $output,
    ) {}

    /**
     * Draw $html on this component's output.
     */
    protected function draw(string $html, int $verbosity = OutputInterface::VERBOSITY_NORMAL): void
    {
        // termwind renders to whatever it was last pointed at, so aim it every time
        renderUsing($this->output);

        render($html, $verbosity);
    }

    /**
     * Escape anything a caller hands us: a message holding &, < or > is text, not markup.
     */
    protected function escape(string $string): string
    {
        return htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
