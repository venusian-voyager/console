<?php

namespace Voyager\Console\View\Components;

use Symfony\Component\Console\Output\OutputInterface;

class Success extends Component
{
    public function render(string $string, int $verbosity = OutputInterface::VERBOSITY_NORMAL): void
    {
        new Line($this->output)->render('success', $string, $verbosity);
    }
}
