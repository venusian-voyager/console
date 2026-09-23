<?php

namespace Voyager\Console\View\Components;

use Symfony\Component\Console\Question\ConfirmationQuestion;

class Confirm extends Component
{
    public function render(string $question, bool $default = false): bool
    {
        return (bool) $this->output->askQuestion(
            new ConfirmationQuestion($question, $default)
        );
    }
}
