<?php

namespace Voyager\Console\View\Components;

use Symfony\Component\Console\Question\Question;

class AskWithCompletion extends Component
{
    /**
     * @param iterable<int, string>|callable(string): array<int, string> $choices
     */
    public function render(string $question, iterable|callable $choices, ?string $default = null): mixed
    {
        $question = new Question($question, $default);

        is_callable($choices)
            ? $question->setAutocompleterCallback($choices)
            : $question->setAutocompleterValues($choices);

        return $this->output->askQuestion($question);
    }
}
