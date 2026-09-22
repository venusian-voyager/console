<?php

namespace Voyager\Console\View\Components;

use Symfony\Component\Console\Question\ChoiceQuestion;

class Choice extends Component
{
    /**
     * @param array<array-key, string> $choices
     * @return string|array<int, string>
     */
    public function render(string $question, array $choices, string|int|null $default = null, ?int $attempts = null, bool $multiple = false): string|array
    {
        $question = new ChoiceQuestion($question, $choices, $default);

        $question->setMaxAttempts($attempts)->setMultiselect($multiple);

        return $this->output->askQuestion($question);
    }
}
