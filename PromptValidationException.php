<?php

namespace Voyager\Console;

use RuntimeException;

/** A prompt's answer failed validation where no prompt can ask again (non-interactive input). */
class PromptValidationException extends RuntimeException
{
    //
}
