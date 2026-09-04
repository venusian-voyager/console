<?php

namespace Voyager\Console\Scheduling;

use Voyager\NutsAndBolts\Reflector;

trait ManagesAttributes
{
    /**
     * The cron expression representing the event's frequency.
     *
     * @var string
     */
    public string $expression = '* * * * *';

    /**
     * How often to repeat the event during a minute.
     *
     * @var int|null
     */
    public ?int $repeatSeconds = null;

    /**
     * The timezone the date should be evaluated on.
     *
     * @var \DateTimeZone|string
     */
    public \DateTimeZone|string|null $timezone = null;

    /**
     * The user the command should run as.
     *
     * @var string|null
     */
    public ?string $user = null;

    /**
     * The list of environments the command should run under.
     *
     * @var array
     */
    public array $environments = [];

    /**
     * Indicates if the command should run in maintenance mode.
     *
     * @var bool
     */
    public bool $evenInMaintenanceMode = false;

    /**
     * Indicates if the command should not overlap itself.
     *
     * @var bool
     */
    public bool $withoutOverlapping = false;

    /**
     * Indicates if the command should only be allowed to run on one server for each cron expression.
     *
     * @var bool
     */
    public bool $onOneServer = false;

    /**
     * The number of minutes the mutex should be valid.
     *
     * @var int
     */
    public int $expiresAt = 1440;

    /**
     * Indicates if the command should run in the background.
     *
     * @var bool
     */
    public bool $runInBackground = false;

    /**
     * The array of filter callbacks.
     *
     * @var array
     */
    protected array $filters = [];

    /**
     * The array of reject callbacks.
     *
     * @var array
     */
    protected array $rejects = [];

    /**
     * The human-readable description of the event.
     *
     * @var string|null
     */
    public ?string $description = null;

    /**
     * Set which user the command should run as.
     *
     * @param  string  $user
     * @return $this
     */
    public function user($user): static
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Limit the environments the command should run in.
     *
     * @param  mixed  $environments
     * @return $this
     */
    public function environments($environments): static
    {
        $this->environments = is_array($environments) ? $environments : func_get_args();

        return $this;
    }

    /**
     * State that the command should run even in maintenance mode.
     *
     * @return $this
     */
    public function evenInMaintenanceMode(): static
    {
        $this->evenInMaintenanceMode = true;

        return $this;
    }

    /**
     * Do not allow the event to overlap each other.
     * The expiration time of the underlying cache lock may be specified in minutes.
     *
     * @param  int  $expiresAt
     * @return $this
     */
    public function withoutOverlapping($expiresAt = 1440): static
    {
        $this->withoutOverlapping = true;

        $this->expiresAt = $expiresAt;

        return $this->skip(function () {
            return $this->mutex->exists($this);
        });
    }

    /**
     * Allow the event to only run on one server for each cron expression.
     *
     * @return $this
     */
    public function onOneServer(): static
    {
        $this->onOneServer = true;

        return $this;
    }

    /**
     * State that the command should run in the background.
     *
     * @return $this
     */
    public function runInBackground(): static
    {
        $this->runInBackground = true;

        return $this;
    }

    /**
     * Register a callback to further filter the schedule.
     *
     * @param  \Closure|bool  $callback
     * @return $this
     */
    public function when($callback): static
    {
        $this->filters[] = Reflector::isCallable($callback) ? $callback : function () use ($callback) {
            return $callback;
        };

        return $this;
    }

    /**
     * Register a callback to further filter the schedule.
     *
     * @param  \Closure|bool  $callback
     * @return $this
     */
    public function skip($callback): static
    {
        $this->rejects[] = Reflector::isCallable($callback) ? $callback : function () use ($callback) {
            return $callback;
        };

        return $this;
    }

    /**
     * Set the human-friendly description of the event.
     *
     * @param  string  $description
     * @return $this
     */
    public function name($description): static
    {
        return $this->description($description);
    }

    /**
     * Set the human-friendly description of the event.
     *
     * @param  string  $description
     * @return $this
     */
    public function description($description): static
    {
        $this->description = $description;

        return $this;
    }
}
