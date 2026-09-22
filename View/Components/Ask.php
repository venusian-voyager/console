<?php

namespace Voyager\Console\View\Components;

use Symfony\Component\Console\Question\Question;

class Ask extends Component
{
    public function render(string $question, ?string $default = null, bool $multiline = false): mixed
    {
        return $this->output->askQuestion(
            new Question($question, $default)->setMultiline($multiline)
        );
    }
}
