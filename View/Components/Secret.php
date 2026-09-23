<?php

namespace Voyager\Console\View\Components;

use Symfony\Component\Console\Question\Question;

class Secret extends Component
{
    public function render(string $question, bool $fallback = true): mixed
    {
        $question = new Question($question);

        $question->setHidden(true)->setHiddenFallback($fallback);

        return $this->output->askQuestion($question);
    }
}
