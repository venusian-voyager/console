<?php

namespace Voyager\Console\Prompts;

use Laravel\Prompts\Key;
use Laravel\Prompts\MultiSelectPrompt;

/**
 * A multi-select whose rows can be disabled, each with its reason. The cursor
 * steps over a disabled row, and no key selects one.
 */
class ChecklistPrompt extends MultiSelectPrompt
{
    /**
     * @param  array<string, string>  $options  Value => label.
     * @param  array<string, string>  $disabled  Value => why it cannot be picked.
     * @param  list<string>  $default  Values selected at the start; disabled ones are dropped.
     */
    public function __construct(
        string $label,
        array $options,
        public array $disabled = [],
        array $default = [],
        string $hint = '',
    ) {
        parent::__construct(
            $label,
            $options,
            array_values(array_diff($default, array_keys($disabled))),
            hint: $hint,
        );

        if ($this->isDisabled($this->highlightedValue())) {
            $this->highlightNext(count($this->options));
        }

        // Home and End land on a row by index; move off it when it is disabled.
        $this->on('key', function (string $key): void {
            if (! $this->isDisabled($this->highlightedValue())) {
                return;
            }

            if (Key::oneOf(Key::END, $key) !== null) {
                $this->highlightPrevious(count($this->options));
            } else {
                $this->highlightNext(count($this->options));
            }
        });
    }

    public function isDisabled(int|string|null $value): bool
    {
        return ! is_null($value) && array_key_exists($value, $this->disabled);
    }

    /**
     * The package finds a renderer by the prompt's exact class, and its
     * default theme takes no additions, so this prompt answers its own.
     */
    protected function getRenderer(): callable
    {
        return new ChecklistPromptRenderer($this);
    }

    protected function highlightNext(int $total, bool $allowNull = false): void
    {
        for ($step = 0; $step < $total; $step++) {
            parent::highlightNext($total, $allowNull);

            if (! $this->isDisabled($this->highlightedValue())) {
                return;
            }
        }
    }

    protected function highlightPrevious(int $total, bool $allowNull = false): void
    {
        for ($step = 0; $step < $total; $step++) {
            parent::highlightPrevious($total, $allowNull);

            if (! $this->isDisabled($this->highlightedValue())) {
                return;
            }
        }
    }

    protected function toggleHighlighted(): void
    {
        if (! $this->isDisabled($this->highlightedValue())) {
            parent::toggleHighlighted();
        }
    }

    protected function toggleAll(): void
    {
        $enabled = array_values(array_diff(array_keys($this->options), array_keys($this->disabled)));

        $this->values = count($this->values) === count($enabled) ? [] : $enabled;
    }
}
