<?php

namespace Voyager\Console\View\Components;

use Symfony\Component\Console\Output\OutputInterface;

class BulletList extends Component
{
    /**
     * @param array<int, string> $elements
     */
    public function render(array $elements, int $verbosity = OutputInterface::VERBOSITY_NORMAL): void
    {
        $items = array_map(fn (string $element): string => '<li>'.$this->escape($element).'</li>', $elements);

        $this->draw('<ul class="mx-2">'.implode('', $items).'</ul>', $verbosity);
    }
}
