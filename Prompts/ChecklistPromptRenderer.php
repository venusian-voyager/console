<?php

namespace Voyager\Console\Prompts;

use Laravel\Prompts\MultiSelectPrompt;
use Laravel\Prompts\Themes\Default\MultiSelectPromptRenderer;

/**
 * The stock multi-select look, with one more row style: a disabled row is dim,
 * has a dash where the checkbox would be, and ends with its reason.
 */
class ChecklistPromptRenderer extends MultiSelectPromptRenderer
{
    /**
     * @param  ChecklistPrompt  $prompt
     */
    protected function renderOptions(MultiSelectPrompt $prompt): string
    {
        $labels = [];
        foreach ($prompt->options as $value => $label) {
            $labels[$value] = $prompt->isDisabled($value) ? "{$label} ({$prompt->disabled[$value]})" : $label;
        }

        $rows = [];
        foreach (array_keys($prompt->visible()) as $value) {
            $label = $this->truncate($labels[$value], $prompt->terminal()->cols() - 12);
            $active = $prompt->highlightedValue() === $value;
            $selected = in_array($value, $prompt->value(), true);

            $rows[] = match (true) {
                $prompt->state === 'cancel' => $this->dim(($active ? '› ' : '  ').($selected ? '◼' : '◻')." {$this->strikethrough($label)}  "),
                $prompt->isDisabled($value) => $this->dim("  – {$label}  "),
                $active && $selected => "{$this->cyan('› ◼')} {$label}  ",
                $active => "{$this->cyan('›')} ◻ {$label}  ",
                $selected => "  {$this->cyan('◼')} {$this->dim($label)}  ",
                default => "  {$this->dim('◻')} {$this->dim($label)}  ",
            };
        }

        return implode(PHP_EOL, $this->scrollbar(
            $rows,
            $prompt->firstVisible,
            $prompt->scroll,
            count($prompt->options),
            min($this->longest($labels, padding: 6), $prompt->terminal()->cols() - 6),
            $prompt->state === 'cancel' ? 'dim' : 'cyan',
        ));
    }
}
