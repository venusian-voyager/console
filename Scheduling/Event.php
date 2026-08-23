<?php

namespace Voyager\Console\Scheduling;

use Closure;
use Cron\CronExpression;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface as HttpClientInterface;
use GuzzleHttp\Exception\TransferException;
use Voyager\Console\Application;
use Voyager\Contracts\Vessel\Vessel;
use Voyager\Contracts\Debug\ExceptionHandler;
use Voyager\Contracts\Mail\Mailer;
use Voyager\Log\Context\Repository;
use Voyager\NutsAndBolts\DataObjects\Arr;
use Voyager\NutsAndBolts\DataObjects\Carbon;
use Voyager\NutsAndBolts\MagicAliases\Date;
use Voyager\NutsAndBolts\DataObjects\Stringable;
use Voyager\NutsAndBolts\Concerns\Macroable;
use Voyager\NutsAndBolts\Concerns\ReflectsClosures;
use Voyager\NutsAndBolts\Concerns\Tappable;
use Psr\Http\Client\ClientExceptionInterface;
use Symfony\Component\Process\Process;
use Throwable;

class Event
{
    use Macroable, ManagesAttributes, ManagesFrequencies, ReflectsClosures, Tappable;

    /**
     * The command string.
     *
     * @var string|null
     */
    public ?string $command = null;

    /**
     * The location that output should be sent to.
     *
     * @var string
     */
    public string $output = '/dev/null';

    /**
     * Indicates whether output should be appended.
     *
     * @var bool
     */
    public bool $shouldAppendOutput = false;

    /**
     * The array of callbacks to be run before the event is started.
     *
     * @var array
     */
    protected array $beforeCallbacks = [];

    /**
     * The array of callbacks to be run after the event is finished.
     *
     * @var array
     */
    protected array $afterCallbacks = [];

    /**
     * The event mutex implementation.
     *
     * @var \Voyager\Console\Scheduling\EventMutex
     */
    public ?EventMutex $mutex = null;

    /**
     * The mutex name resolver callback.
     *
     * @var \Closure|null
     */
    public ?Closure $mutexNameResolver = null;

    /**
     * The last time the event was checked for eligibility to run.
     *
     * Utilized by sub-minute repeated events.
     *
     * @var \Voyager\NutsAndBolts\DataObjects\Carbon|null
     */
    protected ?Carbon $lastChecked = null;

    /**
     * The exit status code of the command.
     *
     * @var int|null
     */
    public ?int $exitCode = null;

    /**
     * Create a new event instance.
     *
     * @param  \Voyager\Console\Scheduling\EventMutex  $mutex
     * @param  string  $command
     * @param  \DateTimeZone|string|null  $timezone
     */
    public function __construct(EventMutex $mutex, $command, $timezone = null)
    {
        $this->mutex = $mutex;
        $this->command = $command;
        $this->timezone = $timezone;

        $this->output = $this->getDefaultOutput();
    }

    /**
     * Get the default output depending on the OS.
     *
     * @return string
     */
    public function getDefaultOutput(): string
    {
        return (DIRECTORY_SEPARATOR === '\\') ? 'NUL' : '/dev/null';
    }

    /**
     * Run the given event.
     *
     * @param  \Voyager\Contracts\Vessel\Vessel  $container
     * @return void
     *
     * @throws \Throwable
     */
    public function run(Vessel $container): mixed
    {
        // Not void: CallbackEvent::run() overrides this and returns the
        // callback's result, and a child may not widen a void return.
        if ($this->shouldSkipDueToOverlapping()) {
            return null;
        }

        $exitCode = $this->start($container);

        if (! $this->runInBackground) {
            $this->finish($container, $exitCode);
        }

        return null;
    }

    /**
     * Determine if the event should skip because another process is overlapping.
     *
     * @return bool
     */
    public function shouldSkipDueToOverlapping(): bool
    {
        return $this->withoutOverlapping && ! $this->mutex->create($this);
    }

    /**
     * Determine if the event has been configured to repeat multiple times per minute.
     *
     * @return bool
     */
    public function isRepeatable(): bool
    {
        return ! is_null($this->repeatSeconds);
    }

    /**
     * Determine if the event is ready to repeat.
     *
     * @return bool
     */
    public function shouldRepeatNow(): bool
    {
        return $this->isRepeatable()
            && $this->lastChecked?->diffInSeconds() >= $this->repeatSeconds;
    }

    /**
     * Run the command process.
     *
     * @param  \Voyager\Contracts\Vessel\Vessel  $container
     * @return int
     *
     * @throws \Throwable
     */
    protected function start($container): int
    {
        try {
            $this->callBeforeCallbacks($container);

            return $this->execute($container);
        } catch (Throwable $exception) {
            $this->removeMutex();

            throw $exception;
        }
    }

    /**
     * Run the command process.
     *
     * @param  \Voyager\Contracts\Vessel\Vessel  $container
     * @return int
     */
    protected function execute($container): int
    {
        $context = json_encode($container[Repository::class]->dehydrate());

        return Process::fromShellCommandline(
            $this->buildCommand(), base_path(), ['__VENUSIAN_CONTEXT' => $context], null, null
        )->run(
            fn () => true
        );
    }

    /**
     * Mark the command process as finished and run callbacks/cleanup.
     *
     * @param  \Voyager\Contracts\Vessel\Vessel  $container
     * @param  int  $exitCode
     * @return void
     */
    public function finish(Vessel $container, $exitCode): void
    {
        $this->exitCode = (int) $exitCode;

        try {
            $this->callAfterCallbacks($container);
        } finally {
            $this->removeMutex();
        }
    }

    /**
     * Call every "before" callback for the event.
     *
     * @param  \Voyager\Contracts\Vessel\Vessel  $container
     * @return void
     */
    public function callBeforeCallbacks(Vessel $container): void
    {
        foreach ($this->beforeCallbacks as $callback) {
            $container->call($callback);
        }
    }

    /**
     * Call every "after" callback for the event.
     *
     * @param  \Voyager\Contracts\Vessel\Vessel  $container
     * @return void
     */
    public function callAfterCallbacks(Vessel $container): void
    {
        foreach ($this->afterCallbacks as $callback) {
            $container->call($callback);
        }
    }

    /**
     * Build the command string.
     *
     * @return string
     */
    public function buildCommand(): string
    {
        return (new CommandBuilder)->buildCommand($this);
    }

    /**
     * Determine if the given event should run based on the Cron expression.
     *
     * @param  \Voyager\Contracts\System\Application  $app
     * @return bool
     */
    public function isDue($app): bool
    {
        if (! $this->runsInMaintenanceMode() && $app->isDownForMaintenance()) {
            return false;
        }

        return $this->expressionPasses() &&
               $this->runsInEnvironment($app->environment());
    }

    /**
     * Determine if the event runs in maintenance mode.
     *
     * @return bool
     */
    public function runsInMaintenanceMode(): bool
    {
        return $this->evenInMaintenanceMode;
    }

    /**
     * Determine if the Cron expression passes.
     *
     * @return bool
     */
    protected function expressionPasses(): bool
    {
        $date = Date::now();

        if ($this->timezone) {
            $date = $date->setTimezone($this->timezone);
        }

        return (new CronExpression($this->expression))->isDue($date->toDateTimeString());
    }

    /**
     * Determine if the event runs in the given environment.
     *
     * @param  string  $environment
     * @return bool
     */
    public function runsInEnvironment($environment): bool
    {
        return empty($this->environments) || in_array($environment, $this->environments);
    }

    /**
     * Determine if the filters pass for the event.
     *
     * @param  \Voyager\Contracts\System\Application  $app
     * @return bool
     */
    public function filtersPass($app): bool
    {
        $this->lastChecked = Date::now();

        foreach ($this->filters as $callback) {
            if (! $app->call($callback)) {
                return false;
            }
        }

        foreach ($this->rejects as $callback) {
            if ($app->call($callback)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ensure that the output is stored on disk in a log file.
     *
     * @return $this
     */
    public function storeOutput(): static
    {
        $this->ensureOutputIsBeingCaptured();

        return $this;
    }

    /**
     * Send the output of the command to a given location.
     *
     * @param  string  $location
     * @param  bool  $append
     * @return $this
     */
    public function sendOutputTo($location, $append = false): static
    {
        $this->output = $location;

        $this->shouldAppendOutput = $append;

        return $this;
    }

    /**
     * Append the output of the command to a given location.
     *
     * @param  string  $location
     * @return $this
     */
    public function appendOutputTo($location): static
    {
        return $this->sendOutputTo($location, true);
    }

    /**
     * E-mail the results of the scheduled operation.
     *
     * @param  mixed  $addresses
     * @param  bool  $onlyIfOutputExists
     * @return $this
     *
     * @throws \LogicException
     */
    public function emailOutputTo($addresses, $onlyIfOutputExists = true): static
    {
        $this->ensureOutputIsBeingCaptured();

        $addresses = Arr::wrap($addresses);

        return $this->then(function (Mailer $mailer) use ($addresses, $onlyIfOutputExists) {
            $this->emailOutput($mailer, $addresses, $onlyIfOutputExists);
        });
    }

    /**
     * E-mail the results of the scheduled operation if it produces output.
     *
     * @param  mixed  $addresses
     * @return $this
     *
     * @throws \LogicException
     */
    public function emailWrittenOutputTo($addresses): static
    {
        return $this->emailOutputTo($addresses, true);
    }

    /**
     * E-mail the results of the scheduled operation if it fails.
     *
     * @param  mixed  $addresses
     * @return $this
     */
    public function emailOutputOnFailure($addresses): static
    {
        $this->ensureOutputIsBeingCaptured();

        $addresses = Arr::wrap($addresses);

        return $this->onFailure(function (Mailer $mailer) use ($addresses) {
            $this->emailOutput($mailer, $addresses, false);
        });
    }

    /**
     * Ensure that the command output is being captured.
     *
     * @return void
     */
    protected function ensureOutputIsBeingCaptured(): void
    {
        if (is_null($this->output) || $this->output == $this->getDefaultOutput()) {
            $this->sendOutputTo(storage_path('logs/schedule-'.sha1($this->mutexName()).'.log'));
        }
    }

    /**
     * E-mail the output of the event to the recipients.
     *
     * @param  \Voyager\Contracts\Mail\Mailer  $mailer
     * @param  array  $addresses
     * @param  bool  $onlyIfOutputExists
     * @return void
     */
    protected function emailOutput(Mailer $mailer, $addresses, $onlyIfOutputExists = true): void
    {
        $text = is_file($this->output) ? file_get_contents($this->output) : '';

        if ($onlyIfOutputExists && empty($text)) {
            return;
        }

        $mailer->raw($text, function ($m) use ($addresses) {
            $m->to($addresses)->subject($this->getEmailSubject());
        });
    }

    /**
     * Get the e-mail subject line for output results.
     *
     * @return string
     */
    protected function getEmailSubject(): string
    {
        if ($this->description) {
            return $this->description;
        }

        return "Scheduled Job Output For [{$this->command}]";
    }

    /**
     * Register a callback to ping a given URL before the job runs.
     *
     * @param  string  $url
     * @return $this
     */
    public function pingBefore($url): static
    {
        return $this->before($this->pingCallback($url));
    }

    /**
     * Register a callback to ping a given URL before the job runs if the given condition is true.
     *
     * @param  bool  $value
     * @param  string  $url
     * @return $this
     */
    public function pingBeforeIf($value, $url): static
    {
        return $value ? $this->pingBefore($url) : $this;
    }

    /**
     * Register a callback to ping a given URL after the job runs.
     *
     * @param  string  $url
     * @return $this
     */
    public function thenPing($url): static
    {
        return $this->then($this->pingCallback($url));
    }

    /**
     * Register a callback to ping a given URL after the job runs if the given condition is true.
     *
     * @param  bool  $value
     * @param  string  $url
     * @return $this
     */
    public function thenPingIf($value, $url): static
    {
        return $value ? $this->thenPing($url) : $this;
    }

    /**
     * Register a callback to ping a given URL if the operation succeeds.
     *
     * @param  string  $url
     * @return $this
     */
    public function pingOnSuccess($url): static
    {
        return $this->onSuccess($this->pingCallback($url));
    }

    /**
     * Register a callback to ping a given URL if the operation succeeds and if the given condition is true.
     *
     * @param  bool  $value
     * @param  string  $url
     * @return $this
     */
    public function pingOnSuccessIf($value, $url): static
    {
        return $value ? $this->onSuccess($this->pingCallback($url)) : $this;
    }

    /**
     * Register a callback to ping a given URL if the operation fails.
     *
     * @param  string  $url
     * @return $this
     */
    public function pingOnFailure($url): static
    {
        return $this->onFailure($this->pingCallback($url));
    }

    /**
     * Register a callback to ping a given URL if the operation fails and if the given condition is true.
     *
     * @param  bool  $value
     * @param  string  $url
     * @return $this
     */
    public function pingOnFailureIf($value, $url): static
    {
        return $value ? $this->onFailure($this->pingCallback($url)) : $this;
    }

    /**
     * Get the callback that pings the given URL.
     *
     * @param  string  $url
     * @return \Closure
     */
    protected function pingCallback($url): Closure
    {
        return function (Vessel $container) use ($url) {
            try {
                $this->getHttpClient($container)->request('GET', $url);
            } catch (ClientExceptionInterface|TransferException $e) {
                $container->make(ExceptionHandler::class)->report($e);
            }
        };
    }

    /**
     * Get the Guzzle HTTP client to use to send pings.
     *
     * @param  \Voyager\Contracts\Vessel\Vessel  $container
     * @return \GuzzleHttp\ClientInterface
     */
    protected function getHttpClient(Vessel $container): HttpClientInterface
    {
        return match (true) {
            $container->bound(HttpClientInterface::class) => $container->make(HttpClientInterface::class),
            $container->bound(HttpClient::class) => $container->make(HttpClient::class),
            default => new HttpClient([
                'connect_timeout' => 10,
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
                'timeout' => 30,
            ]),
        };
    }

    /**
     * Register a callback to be called before the operation.
     *
     * @param  \Closure  $callback
     * @return $this
     */
    public function before(Closure $callback): static
    {
        $this->beforeCallbacks[] = $callback;

        return $this;
    }

    /**
     * Register a callback to be called after the operation.
     *
     * @param  \Closure  $callback
     * @return $this
     */
    public function after(Closure $callback): static
    {
        return $this->then($callback);
    }

    /**
     * Register a callback to be called after the operation.
     *
     * @param  \Closure  $callback
     * @return $this
     */
    public function then(Closure $callback): static
    {
        $parameters = $this->closureParameterTypes($callback);

        if (Arr::get($parameters, 'output') === Stringable::class) {
            return $this->thenWithOutput($callback);
        }

        $this->afterCallbacks[] = $callback;

        return $this;
    }

    /**
     * Register a callback that uses the output after the job runs.
     *
     * @param  \Closure  $callback
     * @param  bool  $onlyIfOutputExists
     * @return $this
     */
    public function thenWithOutput(Closure $callback, $onlyIfOutputExists = false): static
    {
        $this->ensureOutputIsBeingCaptured();

        return $this->then($this->withOutputCallback($callback, $onlyIfOutputExists));
    }

    /**
     * Register a callback to be called if the operation succeeds.
     *
     * @param  \Closure  $callback
     * @return $this
     */
    public function onSuccess(Closure $callback): static
    {
        $parameters = $this->closureParameterTypes($callback);

        if (Arr::get($parameters, 'output') === Stringable::class) {
            return $this->onSuccessWithOutput($callback);
        }

        return $this->then(function (Vessel $container) use ($callback) {
            if ($this->exitCode === 0) {
                $container->call($callback);
            }
        });
    }

    /**
     * Register a callback that uses the output if the operation succeeds.
     *
     * @param  \Closure  $callback
     * @param  bool  $onlyIfOutputExists
     * @return $this
     */
    public function onSuccessWithOutput(Closure $callback, $onlyIfOutputExists = false): static
    {
        $this->ensureOutputIsBeingCaptured();

        return $this->onSuccess($this->withOutputCallback($callback, $onlyIfOutputExists));
    }

    /**
     * Register a callback to be called if the operation fails.
     *
     * @param  \Closure  $callback
     * @return $this
     */
    public function onFailure(Closure $callback): static
    {
        $parameters = $this->closureParameterTypes($callback);

        if (Arr::get($parameters, 'output') === Stringable::class) {
            return $this->onFailureWithOutput($callback);
        }

        return $this->then(function (Vessel $container) use ($callback) {
            if ($this->exitCode !== 0) {
                $container->call($callback);
            }
        });
    }

    /**
     * Register a callback that uses the output if the operation fails.
     *
     * @param  \Closure  $callback
     * @param  bool  $onlyIfOutputExists
     * @return $this
     */
    public function onFailureWithOutput(Closure $callback, $onlyIfOutputExists = false): static
    {
        $this->ensureOutputIsBeingCaptured();

        return $this->onFailure($this->withOutputCallback($callback, $onlyIfOutputExists));
    }

    /**
     * Get a callback that provides output.
     *
     * @param  \Closure  $callback
     * @param  bool  $onlyIfOutputExists
     * @return \Closure
     */
    protected function withOutputCallback(Closure $callback, $onlyIfOutputExists = false): Closure
    {
        return function (Vessel $container) use ($callback, $onlyIfOutputExists) {
            $output = $this->output && is_file($this->output) ? file_get_contents($this->output) : '';

            return $onlyIfOutputExists && empty($output)
                ? null
                : $container->call($callback, ['output' => new Stringable($output)]);
        };
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

        return $this->buildCommand();
    }

    /**
     * Determine the next due date for an event.
     *
     * @param  \DateTimeInterface|string  $currentTime
     * @param  int  $nth
     * @param  bool  $allowCurrentDate
     * @return \Voyager\NutsAndBolts\DataObjects\Carbon
     */
    public function nextRunDate($currentTime = 'now', $nth = 0, $allowCurrentDate = false): Carbon
    {
        return Date::instance((new CronExpression($this->getExpression()))
            ->getNextRunDate($currentTime, $nth, $allowCurrentDate, $this->timezone));
    }

    /**
     * Get the Cron expression for the event.
     *
     * @return string
     */
    public function getExpression(): string
    {
        return $this->expression;
    }

    /**
     * Set the event mutex implementation to be used.
     *
     * @param  \Voyager\Console\Scheduling\EventMutex  $mutex
     * @return $this
     */
    public function preventOverlapsUsing(EventMutex $mutex): static
    {
        $this->mutex = $mutex;

        return $this;
    }

    /**
     * Get the mutex name for the scheduled command.
     *
     * @return string
     */
    public function mutexName(): string
    {
        $mutexNameResolver = $this->mutexNameResolver;

        if (! is_null($mutexNameResolver) && is_callable($mutexNameResolver)) {
            return $mutexNameResolver($this);
        }

        return 'framework'.DIRECTORY_SEPARATOR.'schedule-'.
            sha1($this->expression.$this->normalizeCommand($this->command ?? ''));
    }

    /**
     * Set the mutex name or name resolver callback.
     *
     * @param  \Closure|string  $mutexName
     * @return $this
     */
    public function createMutexNameUsing(Closure|string $mutexName): static
    {
        $this->mutexNameResolver = is_string($mutexName) ? fn () => $mutexName : $mutexName;

        return $this;
    }

    /**
     * Delete the mutex for the event.
     *
     * @return void
     */
    protected function removeMutex(): void
    {
        if ($this->withoutOverlapping) {
            $this->mutex->forget($this);
        }
    }

    /**
     * Format the given command string with a normalized PHP binary path.
     *
     * @param  string  $command
     * @return string
     */
    public static function normalizeCommand($command): string
    {
        return str_replace([
            Application::phpBinary(),
            Application::computerBinary(),
        ], [
            'php',
            preg_replace("#['\"]#", '', Application::computerBinary()),
        ], $command);
    }
}
