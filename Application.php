<?php

namespace Voyager\Console;

use Closure;
use Voyager\Console\Events\ComputerStarting;
use Voyager\Contracts\Console\Application as ApplicationContract;
use Voyager\Contracts\Vessel\Vessel;
use Voyager\Contracts\Events\Dispatcher;
use Voyager\NutsAndBolts\ProcessUtils;
use ReflectionClass;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

use function Voyager\NutsAndBolts\computer_binary;
use function Voyager\NutsAndBolts\php_binary;

class Application extends SymfonyApplication implements ApplicationContract
{
    /**
     * The Venusian application instance.
     *
     * @var \Voyager\Contracts\Vessel\Vessel
     */
    protected ?Vessel $venusian = null;

    /**
     * The event dispatcher instance.
     *
     * @var \Voyager\Contracts\Events\Dispatcher
     */
    protected ?Dispatcher $events = null;

    /**
     * The output from the previous command.
     *
     * @var \Symfony\Component\Console\Output\BufferedOutput
     */
    protected ?BufferedOutput $lastOutput = null;

    /**
     * The console application bootstrappers.
     *
     * @var array<array-key, \Closure($this): void>
     */
    protected static array $bootstrappers = [];

    /**
     * A map of command names to classes.
     *
     * @var array<string, \Voyager\Console\Command|string>
     */
    protected array $commandMap = [];

    /**
     * Create a new Computer console application.
     *
     * @param  \Voyager\Contracts\Vessel\Vessel  $venusian
     * @param  \Voyager\Contracts\Events\Dispatcher  $events
     * @param  string  $version
     */
    public function __construct(Vessel $venusian, Dispatcher $events, $version)
    {
        parent::__construct('Venusian Framework', $version);

        $this->venusian = $venusian;
        $this->events = $events;
        $this->setAutoExit(false);
        $this->setCatchExceptions(false);

        $this->events->dispatch(new ComputerStarting($this));

        $this->bootstrap();
    }

    /**
     * Determine the proper PHP executable.
     *
     * @return string
     */
    public static function phpBinary(): string
    {
        return ProcessUtils::escapeArgument(php_binary());
    }

    /**
     * Determine the proper Computer executable.
     *
     * @return string
     */
    public static function computerBinary(): string
    {
        return ProcessUtils::escapeArgument(computer_binary());
    }

    /**
     * Format the given command as a fully-qualified executable command.
     *
     * @param  string  $string
     * @return string
     */
    public static function formatCommandString($string): string
    {
        return sprintf('%s %s %s', static::phpBinary(), static::computerBinary(), $string);
    }

    /**
     * Register a console "starting" bootstrapper.
     *
     * @param  \Closure($this): void  $callback
     * @return void
     */
    public static function starting(Closure $callback): void
    {
        static::$bootstrappers[] = $callback;
    }

    /**
     * Bootstrap the console application.
     *
     * @return void
     */
    protected function bootstrap(): void
    {
        foreach (static::$bootstrappers as $bootstrapper) {
            $bootstrapper($this);
        }
    }

    /**
     * Clear the console application bootstrappers.
     *
     * @return void
     */
    public static function forgetBootstrappers(): void
    {
        static::$bootstrappers = [];
    }

    /**
     * Run an Computer console command by name.
     *
     * @param  \Symfony\Component\Console\Command\Command|string  $command
     * @param  array  $parameters
     * @param  \Symfony\Component\Console\Output\OutputInterface|null  $outputBuffer
     * @return int
     *
     * @throws \Symfony\Component\Console\Exception\CommandNotFoundException
     */
    // Untyped return: upstream tests mock this class, and a Mockery double
    // returns null rather than an int.
    public function call($command, array $parameters = [], $outputBuffer = null)
    {
        [$command, $input] = $this->parseCommand($command, $parameters);

        if (! $this->has($command)) {
            throw new CommandNotFoundException(sprintf('The command "%s" does not exist.', $command));
        }

        return $this->run(
            $input, $this->lastOutput = $outputBuffer ?: new BufferedOutput
        );
    }

    /**
     * Parse the incoming Computer command and its input.
     *
     * @param  \Symfony\Component\Console\Command\Command|string  $command
     * @param  array  $parameters
     * @return array<string, \Symfony\Component\Console\Input\ArrayInput>
     */
    protected function parseCommand($command, $parameters)
    {
        if (is_subclass_of($command, SymfonyCommand::class)) {
            $callingClass = true;

            if (is_object($command)) {
                $command = get_class($command);
            }

            $command = $this->venusian->make($command)->getName();
        }

        if (! isset($callingClass) && empty($parameters)) {
            $command = $this->getCommandName($input = new StringInput($command));
        } else {
            array_unshift($parameters, $command);

            $input = new ArrayInput($parameters);
        }

        return [$command, $input];
    }

    /**
     * Get the output for the last run command.
     *
     * @return string
     */
    public function output(): string
    {
        return $this->lastOutput && method_exists($this->lastOutput, 'fetch')
            ? $this->lastOutput->fetch()
            : '';
    }

    /**
     * Add an array of commands to the console.
     *
     * @param  array<int, \Symfony\Component\Console\Command\Command>  $commands
     * @return void
     */
    #[\Override]
    public function addCommands(array $commands): void
    {
        foreach ($commands as $command) {
            $this->addCommand($command);
        }
    }

    /**
     * Add a command to the console.
     *
     * @param  \Symfony\Component\Console\Command\Command  $command
     * @return \Symfony\Component\Console\Command\Command|null
     */
    #[\Override]
    public function add(SymfonyCommand $command): ?SymfonyCommand
    {
        return $this->addCommand($command);
    }

    /**
     * Add a command to the console.
     *
     * @param  \Symfony\Component\Console\Command\Command|callable  $command
     * @return \Symfony\Component\Console\Command\Command|null
     */
    public function addCommand(SymfonyCommand|callable $command): ?SymfonyCommand
    {
        if ($command instanceof Command) {
            $command->setVenusian($this->venusian);
        }

        return $this->addToParent($command);
    }

    /**
     * Add the command to the parent instance.
     *
     * @param  \Symfony\Component\Console\Command\Command  $command
     * @return \Symfony\Component\Console\Command\Command
     */
    protected function addToParent(SymfonyCommand $command): SymfonyCommand
    {
        if (method_exists(SymfonyApplication::class, 'addCommand')) {
            return parent::addCommand($command);
        }

        return parent::add($command);
    }

    /**
     * Add a command, resolving through the application.
     *
     * @param  \Voyager\Console\Command|string  $command
     * @return \Symfony\Component\Console\Command\Command|null
     */
    public function resolve($command): ?SymfonyCommand
    {
        if (is_subclass_of($command, SymfonyCommand::class)) {
            $attribute = (new ReflectionClass($command))->getAttributes(AsCommand::class);

            $commandName = ! empty($attribute) ? $attribute[0]->newInstance()->name : null;

            if (! is_null($commandName)) {
                foreach (explode('|', $commandName) as $name) {
                    $this->commandMap[$name] = $command;
                }

                return null;
            }
        }

        if ($command instanceof Command) {
            return $this->add($command);
        }

        return $this->add($this->venusian->make($command));
    }

    /**
     * Resolve an array of commands through the application.
     *
     * @param  mixed  $commands
     * @return $this
     */
    public function resolveCommands($commands): static
    {
        $commands = is_array($commands) ? $commands : func_get_args();

        foreach ($commands as $command) {
            $this->resolve($command);
        }

        return $this;
    }

    /**
     * Set the container command loader for lazy resolution.
     *
     * @return $this
     */
    public function setContainerCommandLoader(): static
    {
        $this->setCommandLoader(new ContainerCommandLoader($this->venusian, $this->commandMap));

        return $this;
    }

    /**
     * Get the default input definition for the application.
     *
     * This is used to add the --env option to every available command.
     *
     * @return \Symfony\Component\Console\Input\InputDefinition
     */
    #[\Override]
    protected function getDefaultInputDefinition(): InputDefinition
    {
        return tap(parent::getDefaultInputDefinition(), function ($definition) {
            $definition->addOption($this->getEnvironmentOption());
        });
    }

    /**
     * Get the global environment option for the definition.
     *
     * @return \Symfony\Component\Console\Input\InputOption
     */
    protected function getEnvironmentOption(): InputOption
    {
        $message = 'The environment the command should run under';

        return new InputOption('--env', null, InputOption::VALUE_OPTIONAL, $message);
    }

    /**
     * Get the Venusian application instance.
     *
     * @return \Voyager\Contracts\System\Application
     */
    public function getVenusian(): \Voyager\Contracts\System\Application
    {
        return $this->venusian;
    }
}
