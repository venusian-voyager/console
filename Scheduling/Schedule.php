<?php

namespace Voyager\Console\Scheduling;

use function Voyager\NutsAndBolts\Helpers\enum_value;

use BadMethodCallException;
use Closure;
use DateTimeInterface;
use Voyager\Bus\UniqueLock;
use Voyager\Console\Application;
use Voyager\Vessel\Vessel;
use Voyager\Contracts\Bus\Dispatcher;
use Voyager\Contracts\Cache\Repository as Cache;
use Voyager\Contracts\Vessel\BindingResolutionException;
use Voyager\Contracts\Queue\ShouldBeUnique;
use Voyager\Contracts\Queue\ShouldQueue;
use Voyager\Queue\CallQueuedClosure;
use Voyager\NutsAndBolts\Collection;
use Voyager\NutsAndBolts\ProcessUtils;
use Voyager\NutsAndBolts\Concerns\Macroable;
use RuntimeException;
use Symfony\Component\Console\Command\Command as SymfonyCommand;


/**
 * @mixin \Voyager\Console\Scheduling\PendingEventAttributes
 */
class Schedule
{
    use Macroable {
        __call as macroCall;
    }

    const SUNDAY = 0;

    const MONDAY = 1;

    const TUESDAY = 2;

    const WEDNESDAY = 3;

    const THURSDAY = 4;

    const FRIDAY = 5;

    const SATURDAY = 6;

    /**
     * Every event on the schedule.
     *
     * @var \Voyager\Console\Scheduling\Event[]
     */
    protected array $events = [];

    /**
     * The event mutex implementation.
     *
     * @var \Voyager\Console\Scheduling\EventMutex
     */
    protected ?EventMutex $eventMutex = null;

    /**
     * The scheduling mutex implementation.
     *
     * @var \Voyager\Console\Scheduling\SchedulingMutex
     */
    protected ?SchedulingMutex $schedulingMutex = null;

    /**
     * The timezone the date should be evaluated on.
     *
     * @var \DateTimeZone|string
     */
    protected \DateTimeZone|string|null $timezone = null;

    /**
     * The job dispatcher implementation.
     *
     * @var \Voyager\Contracts\Bus\Dispatcher
     */
    protected ?Dispatcher $dispatcher = null;

    /**
     * The cache of mutex results.
     *
     * @var array<string, bool>
     */
    protected array $mutexCache = [];

    /**
     * The attributes to pass to the event.
     *
     * @var \Voyager\Console\Scheduling\PendingEventAttributes|null
     */
    protected ?PendingEventAttributes $attributes = null;

    /**
     * The schedule group attributes stack.
     *
     * @var array<int, PendingEventAttributes>
     */
    protected array $groupStack = [];

    /**
     * Create a new schedule instance.
     *
     * @param  \DateTimeZone|string|null  $timezone
     *
     * @throws \RuntimeException
     */
    public function __construct($timezone = null)
    {
        $this->timezone = $timezone;

        if (! class_exists(Vessel::class)) {
            throw new RuntimeException(
                'A container implementation is required to use the scheduler. Please install the illuminate/container package.'
            );
        }

        $vessel = Vessel::getInstance();

        $this->eventMutex = $vessel->bound(EventMutex::class)
            ? $vessel->make(EventMutex::class)
            : $vessel->make(CacheEventMutex::class);

        $this->schedulingMutex = $vessel->bound(SchedulingMutex::class)
            ? $vessel->make(SchedulingMutex::class)
            : $vessel->make(CacheSchedulingMutex::class);
    }

    /**
     * Add a new callback event to the schedule.
     *
     * @param  string|callable  $callback
     * @param  array  $parameters
     * @return \Voyager\Console\Scheduling\CallbackEvent
     */
    public function call($callback, array $parameters = []): CallbackEvent
    {
        $this->events[] = $event = new CallbackEvent(
            $this->eventMutex, $callback, $parameters, $this->timezone
        );

        $this->mergePendingAttributes($event);

        return $event;
    }

    /**
     * Add a new Computer command event to the schedule.
     *
     * @param  \Symfony\Component\Console\Command\Command|string  $command
     * @param  array  $parameters
     * @return \Voyager\Console\Scheduling\Event
     */
    public function command($command, array $parameters = []): Event
    {
        if ($command instanceof SymfonyCommand) {
            $command = get_class($command);

            $command = Vessel::getInstance()->make($command);

            return $this->exec(
                Application::formatCommandString($command->getName()), $parameters,
            )->description($command->getDescription());
        }

        if (class_exists($command)) {
            $command = Vessel::getInstance()->make($command);

            return $this->exec(
                Application::formatCommandString($command->getName()), $parameters,
            )->description($command->getDescription());
        }

        return $this->exec(
            Application::formatCommandString($command), $parameters
        );
    }

    /**
     * Add a new job callback event to the schedule.
     *
     * @param  object|string  $job
     * @param  \UnitEnum|string|null  $queue
     * @param  \UnitEnum|string|null  $connection
     * @return \Voyager\Console\Scheduling\CallbackEvent
     */
    public function job($job, $queue = null, $connection = null): CallbackEvent
    {
        $jobName = $job;

        $queue = enum_value($queue);
        $connection = enum_value($connection);

        if (! is_string($job)) {
            $jobName = method_exists($job, 'displayName')
                ? $job->displayName()
                : $job::class;
        }

        $this->events[] = $event = new CallbackEvent(
            $this->eventMutex, function () use ($job, $queue, $connection) {
                $job = is_string($job) ? Vessel::getInstance()->make($job) : $job;

                if ($job instanceof ShouldQueue) {
                    $this->dispatchToQueue($job, $queue ?? $job->queue, $connection ?? $job->connection);
                } else {
                    $this->dispatchNow($job);
                }
            }, [], $this->timezone
        );

        $event->name($jobName);

        $this->mergePendingAttributes($event);

        return $event;
    }

    /**
     * Dispatch the given job to the queue.
     *
     * @param  object  $job
     * @param  string|null  $queue
     * @param  string|null  $connection
     * @return void
     *
     * @throws \RuntimeException
     */
    protected function dispatchToQueue($job, $queue, $connection)
    {
        if ($job instanceof Closure) {
            if (! class_exists(CallQueuedClosure::class)) {
                throw new RuntimeException(
                    'To enable support for closure jobs, please install the illuminate/queue package.'
                );
            }

            $job = CallQueuedClosure::create($job);
        }

        if ($job instanceof ShouldBeUnique) {
            return $this->dispatchUniqueJobToQueue($job, $queue, $connection);
        }

        $this->getDispatcher()->dispatch(
            $job->onConnection($connection)->onQueue($queue)
        );
    }

    /**
     * Dispatch the given unique job to the queue.
     *
     * @param  object  $job
     * @param  string|null  $queue
     * @param  string|null  $connection
     * @return void
     *
     * @throws \RuntimeException
     */
    protected function dispatchUniqueJobToQueue($job, $queue, $connection)
    {
        if (! Vessel::getInstance()->bound(Cache::class)) {
            throw new RuntimeException('Cache driver not available. Scheduling unique jobs not supported.');
        }

        if (! (new UniqueLock(Vessel::getInstance()->make(Cache::class)))->acquire($job)) {
            return;
        }

        $this->getDispatcher()->dispatch(
            $job->onConnection($connection)->onQueue($queue)
        );
    }

    /**
     * Dispatch the given job right now.
     *
     * @param  object  $job
     * @return void
     */
    protected function dispatchNow($job)
    {
        $this->getDispatcher()->dispatchNow($job);
    }

    /**
     * Add a new command event to the schedule.
     *
     * @param  string  $command
     * @param  array  $parameters
     * @return \Voyager\Console\Scheduling\Event
     */
    public function exec($command, array $parameters = []): Event
    {
        if (count($parameters)) {
            $command .= ' '.$this->compileParameters($parameters);
        }

        $this->events[] = $event = new Event($this->eventMutex, $command, $this->timezone);

        $this->mergePendingAttributes($event);

        return $event;
    }

    /**
     * Create new schedule group.
     *
     * @param  \Closure  $events
     * @return void
     *
     * @throws \RuntimeException
     */
    public function group(Closure $events)
    {
        if ($this->attributes === null) {
            throw new RuntimeException('Invoke an attribute method such as Schedule::daily() before defining a schedule group.');
        }

        $this->groupStack[] = $this->attributes;
        $this->attributes = null;

        $events($this);

        array_pop($this->groupStack);
    }

    /**
     * Merge the current group attributes with the given event.
     *
     * @param  \Voyager\Console\Scheduling\Event  $event
     * @return void
     */
    protected function mergePendingAttributes(Event $event)
    {
        if (! empty($this->groupStack)) {
            $group = array_last($this->groupStack);

            $group->mergeAttributes($event);
        }

        if (isset($this->attributes)) {
            $this->attributes->mergeAttributes($event);

            $this->attributes = null;
        }
    }

    /**
     * Compile parameters for a command.
     *
     * @param  array  $parameters
     * @return string
     */
    protected function compileParameters(array $parameters): string
    {
        return (new Collection($parameters))->map(function ($value, $key) {
            if (is_array($value)) {
                return $this->compileArrayInput($key, $value);
            }

            if (! is_numeric($value) && ! preg_match('/^(-.$|--.*)/i', $value)) {
                $value = ProcessUtils::escapeArgument($value);
            }

            return is_numeric($key) ? $value : "{$key}={$value}";
        })->implode(' ');
    }

    /**
     * Compile array input for a command.
     *
     * @param  string|int  $key
     * @param  array  $value
     * @return string
     */
    public function compileArrayInput($key, $value): string
    {
        $value = (new Collection($value))->map(function ($value) {
            return ProcessUtils::escapeArgument($value);
        });

        if (str_starts_with($key, '--')) {
            $value = $value->map(function ($value) use ($key) {
                return "{$key}={$value}";
            });
        } elseif (str_starts_with($key, '-')) {
            $value = $value->map(function ($value) use ($key) {
                return "{$key} {$value}";
            });
        }

        return $value->implode(' ');
    }

    /**
     * Determine if the server is allowed to run this event.
     *
     * @param  \Voyager\Console\Scheduling\Event  $event
     * @param  \DateTimeInterface  $time
     * @return bool
     */
    public function serverShouldRun(Event $event, DateTimeInterface $time): bool
    {
        return $this->mutexCache[$event->mutexName()] ??= $this->schedulingMutex->create($event, $time);
    }

    /**
     * Get every event on the schedule that is due.
     *
     * @param  \Voyager\Contracts\System\Application  $app
     * @return \Voyager\NutsAndBolts\Collection
     */
    public function dueEvents($app): Collection
    {
        return (new Collection($this->events))->filter->isDue($app);
    }

    /**
     * Get every event on the schedule.
     *
     * @return \Voyager\Console\Scheduling\Event[]
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * Specify the cache store that should be used to store mutexes.
     *
     * @param  \UnitEnum|string  $store
     * @return $this
     */
    public function useCache($store): static
    {
        $store = enum_value($store);

        if ($this->eventMutex instanceof CacheAware) {
            $this->eventMutex->useStore($store);
        }

        if ($this->schedulingMutex instanceof CacheAware) {
            $this->schedulingMutex->useStore($store);
        }

        return $this;
    }

    /**
     * Get the job dispatcher, if available.
     *
     * @return \Voyager\Contracts\Bus\Dispatcher
     *
     * @throws \RuntimeException
     */
    protected function getDispatcher(): Dispatcher
    {
        if ($this->dispatcher === null) {
            try {
                $this->dispatcher = Vessel::getInstance()->make(Dispatcher::class);
            } catch (BindingResolutionException $e) {
                throw new RuntimeException(
                    'Unable to resolve the dispatcher from the service container. Please bind it or install the illuminate/bus package.',
                    is_int($e->getCode()) ? $e->getCode() : 0, $e
                );
            }
        }

        return $this->dispatcher;
    }

    /**
     * Dynamically handle calls into the schedule instance.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters): mixed
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        if (method_exists(PendingEventAttributes::class, $method) || Event::hasMacro($method)) {
            $this->attributes ??= $this->groupStack ? clone array_last($this->groupStack) : new PendingEventAttributes($this);

            return $this->attributes->$method(...$parameters);
        }

        throw new BadMethodCallException(sprintf(
            'Method %s::%s does not exist.', static::class, $method
        ));
    }
}
