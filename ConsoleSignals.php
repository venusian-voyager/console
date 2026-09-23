<?php

namespace Voyager\Console;

use Symfony\Component\Console\SignalRegistry\SignalRegistry;

class ConsoleSignals
{
    /**
     * The signal registry instance.
     *
     * @var SignalRegistry
     */
    // Untyped: tests inject a fake registry that does not extend Symfony's.
    protected SignalRegistry $registry;

    /**
     * The signal registry's previous list of handlers.
     *
     * @var array<int, array<int, callable(int): void>>|null
     */
    protected ?array $previous_handlers;

    /**
     * The current availability resolver, if any.
     *
     * @var (callable(): bool)|null
     */
    protected static $availability_resolver;

    /**
     * Create a new signal registrar instance.
     *
     * @param SignalRegistry $registry
     */
    public function __construct(SignalRegistry $registry)
    {
        $this->registry = $registry;

        $this->previous_handlers = $this->getHandlers();
    }

    /**
     * Register a new signal handler.
     *
     * @param int $signal
     * @param callable(int $signal): void $callback
     * @return void
     */
    public function register(int $signal, callable $callback): void
    {
        $this->previous_handlers[$signal] ??= $this->initializeSignal($signal);

        $handlers = $this->getHandlers();

        $handlers[$signal] ??= $this->initializeSignal($signal);

        $this->setHandlers($handlers);

        $this->registry->register($signal, $callback);

        $handlers = $this->getHandlers();

        $lastHandlerInserted = array_pop($handlers[$signal]);

        array_unshift($handlers[$signal], $lastHandlerInserted);

        $this->setHandlers($handlers);
    }

    /**
     * Gets the signal's existing handler in array format.
     *
     * @return array<int, callable(int $signal): void>|null
     */
    protected function initializeSignal($signal): ?array
    {
        return is_callable($existingHandler = pcntl_signal_get_handler($signal))
            ? [$existingHandler]
            : null;
    }

    /**
     * Unregister the current signal handlers.
     *
     * @return void
     */
    public function unregister(): void
    {
        $previous_handlers = $this->previous_handlers;

        foreach ($previous_handlers as $signal => $handler) {
            if (is_null($handler)) {
                pcntl_signal($signal, SIG_DFL);

                unset($previous_handlers[$signal]);
            }
        }

        $this->setHandlers($previous_handlers);
    }

    /**
     * Execute the given callback if "signals" should be used and are available.
     *
     * @param callable $callback
     * @return void
     */
    public static function whenAvailable(callable $callback): void
    {
        $resolver = static::$availability_resolver;

        if ($resolver()) {
            $callback();
        }
    }

    /**
     * Get the registry's handlers.
     *
     * @return array<int, array<int, callable>>
     */
    protected function getHandlers(): array
    {
        return (fn () => $this->signalHandlers)
            ->call($this->registry);
    }

    /**
     * Set the registry's handlers.
     *
     * @param array<int, array<int, callable(int $signal):void>> $handlers
     * @return void
     */
    protected function setHandlers(array $handlers): void
    {
        (fn () => $this->signal_handlers = $handlers)
            ->call($this->registry);
    }

    /**
     * Set the availability resolver.
     *
     * @param (callable(): bool) $resolver
     * @return void
     */
    public static function resolveAvailabilityUsing(callable $resolver): void
    {
        static::$availability_resolver = $resolver;
    }
}