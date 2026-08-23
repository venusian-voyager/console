<?php

namespace Voyager\Console\View\Components\Mutators;

use Voyager\NutsAndBolts\DataObjects\Stringable;

class EnsurePunctuation
{
    /**
     * Ensures the given string ends with punctuation.
     *
     * @param  string  $string
     * @return string
     */
    public function __invoke($string): string
    {
        if (! (new Stringable($string))->endsWith(['.', '?', '!', ':'])) {
            return "$string.";
        }

        return $string;
    }
}
