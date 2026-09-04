<?php

namespace Voyager\Console\Scheduling;

use Voyager\Contracts\Vessel\Vessel;
use Voyager\NutsAndBolts\Reflector;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

class CallbackEvent extends Event
{
    /**
     * The callback to call.
     *
     * @var string
     */
    protected mixed $callback;

    /**
     * The parameters to pass to the method.
     *
     * @var array
     */
    protected array $parameters;

    /**
     * The result of the callback's execution.
     *
     * @var mixed
     */
    protected mixed $result = null;

    /**
     * The exception that was thrown when calling the callback, if any.
     *
     * @var \Throwable|null
     */
    protected ?Throwable $exception = null;

    /**
     * Create a new event instance.
     *
     * @param  \Voyager\Console\Scheduling\EventMutex  $mutex
     * @param  string|callable  $callback
     * @param  array  $parameters
     * @param  \DateTimeZone|string|null  $timezone
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(EventMutex $mutex, $callback, array $parameters = [], $timezone = null)
    {
        if (! is_string($callback) && ! Reflector::isCallable($callback)) {
            throw new InvalidArgumentException(
                'Invalid scheduled callback event. Must be a string or callable.'
            );
        }

        $this->mutex = $mutex;
        $this->callback = $callback;
        $this->parameters = $parameters;
        $this->timezone = $timezone;
    }

    /**
     * Run the callback event.
     *
     * @param  \Voyager\Contracts\Vessel\Vessel  $container
     * @return mixed
     *
     * @throws \Throwable
     */
    public function run(Vessel $container): mixed
    {
        parent::run($container);

        if ($this->exception) {
            throw $this->exception;
        }

        return $this->result;
    }

    /**
     * Determine if the event should skip because another process is overlapping.
     *
     * @return bool
     */
    public function shouldSkipDueToOverlapping(): bool
    {
        return $this->description && parent::shouldSkipDueToOverlapping();
    }

    /**
     * Indicate that the callback should run in the background.
     *
     * @return void
     *
     * @throws \RuntimeException
     */
    public function runInBackground(): static
    {
        throw new RuntimeException('Scheduled closures can not be run in the background.');
    }

    /**
     * Run the callback.
     *
     * @param  \Voyager\Contracts\Vessel\Vessel  $container
     * @return int
     */
    protected function execute($container): int
    {
        try {
            $this->result = is_object($this->callback)
                ? $container->call([$this->callback, '__invoke'], $this->parameters)
                : $container->call($this->callback, $this->parameters);

            return $this->result === false ? 1 : 0;
        } catch (Throwable $e) {
            $this->exception = $e;

            return 1;
        }
    }

    /**
     * Do not allow the event to overlap each other.
     *
     * The expiration time of the underlying cache lock may be specified in minutes.
     *
     * @param  int  $expiresAt
     * @return $this
     *
     * @throws \LogicException
     */
    public function withoutOverlapping($expiresAt = 1440): static
    {
        if (! isset($this->description)) {
            throw new LogicException(
                "A scheduled event name is required to prevent overlapping. Use the 'name' method before 'withoutOverlapping'."
            );
        }

        return parent::withoutOverlapping($expiresAt);
    }

    /**
     * Allow the event to only run on one server for each cron expression.
     *
     * @return $this
     *
     * @throws \LogicException
     */
    public function onOneServer(): static
    {
        if (! isset($this->description)) {
            throw new LogicException(
                "A scheduled event name is required to only run on one server. Use the 'name' method before 'onOneServer'."
            );
        }

        return parent::onOneServer();
    }

    /**
     * Get the summary of the event for display.
     *
     * @return string
     */
    public function getSummaryForDisplay(): string
    {
        if (is_string($this->description)) {
            return $this->description;
        }

        return is_string($this->callback) ? $this->callback : 'Callback';
    }

    /**
     * Get the mutex name for the scheduled command.
     *
     * @return string
     */
    public function mutexName(): string
    {
        return 'framework/schedule-'.sha1($this->description ?? '');
    }

    /**
     * Clear the mutex for the event.
     *
     * @return void
     */
    protected function removeMutex(): void
    {
        if ($this->description) {
            parent::removeMutex();
        }
    }
}
