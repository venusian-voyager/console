<?php

namespace Voyager\Console;

use Override;
use Exception;
use ReflectionClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\ContainerCommandLoader;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\StringInput;
use Voyager\Console\Command as VoyagerCommand;
use function Voyager\NutsAndBolts\php_binary;
use function Voyager\NutsAndBolts\computer_binary;
use Voyager\Contracts\Vessel\TheServiceContainer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Voyager\Contracts\Console\ConsoleApplication as ApplicationContract;

class ComputerConsoleInstance extends SymfonyApplication implements ApplicationContract
{
    /**
     * The Venusian application instance.
     *
     * @var TheServiceContainer
     */
    protected TheServiceContainer $venusian;

    /**
     * A map of command names to classes.
     *
     * @var array<string, \Voyager\Console\Command|string>
     */
    protected array $command_map = [];

    /**
     * The output from the previous command.
     *
     * @var BufferedOutput|null
     */
    protected ?BufferedOutput $last_output = null;

    /**
     * The console application bootstrappers.
     *
     * @var array<array-key, callable($this): void>
     */
    protected static array $bootstrappers = [];

    public function __construct(
        TheServiceContainer $venusian,
        //Dispatcher $events,
        string $version
    ) {
        parent::__construct('Venusian Framework', $version);

        $this->venusian = $venusian;
        $this->setAutoExit(false);
        $this->setCatchExceptions(false);

        $this->bootstrap();
    }

    /**
     * Run a Computer console command by name.
     *
     * @param  Command|string  $command
     * @param  array  $parameters
     * @param OutputInterface|null $outputBuffer
     * @return int
     *
     * @throws CommandNotFoundException|Exception
     */
    // Untyped return: upstream tests mock this class, and a Mockery double
    // returns null rather than an int.
    public function call($command, array $parameters = [], ?OutputInterface $outputBuffer = null): int
    {
        [$command, $input] = $this->parseCommand($command, $parameters);

        if (! $this->has($command)) {
            throw new CommandNotFoundException(sprintf('The command "%s" does not exist.', $command));
        }

        return $this->run(
            $input, $this->last_output = $outputBuffer ?: new BufferedOutput
        );
    }

    /**
     * Get the output for the last run command.
     *
     * @return string
     */
    public function output(): string
    {
        return $this->last_output && method_exists($this->last_output, 'fetch')
            ? $this->last_output->fetch()
            : '';
    }

    /**
     * Resolve an array of commands through the application.
     *
     * @param mixed $commands
     * @return $this
     * @throws \ReflectionException
     */
    public function resolveCommands(mixed $commands): static
    {
        $commands = is_array($commands) ? $commands : func_get_args();

        foreach ($commands as $command) {
            $this->resolve($command);
        }

        return $this;
    }

    /**
     * Add a command, resolving through the application.
     *
     * @param Command|string $command
     * @return SymfonyCommand|null
     * @throws \ReflectionException
     */
    public function resolve($command): ?SymfonyCommand
    {
        if (is_subclass_of($command, SymfonyCommand::class)) {
            $attribute = new ReflectionClass($command)->getAttributes(AsCommand::class);

            $commandName = ! empty($attribute) ? $attribute[0]->newInstance()->name : null;

            if (! is_null($commandName)) {
                foreach (explode('|', $commandName) as $name) {
                    $this->command_map[$name] = $command;
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
     * Add a command to the console.
     *
     * @param SymfonyCommand $command
     * @return SymfonyCommand|null
     */
    public function add(SymfonyCommand $command): ?SymfonyCommand
    {
        return $this->addCommand($command);
    }

    /**
     * Hand a command the container before the console can run it.
     *
     * Symfony resolves a lazily-loaded command straight through here — has() calls addCommand(),
     * never add() — so this is the one gate both registration paths pass.
     *
     * @param callable|SymfonyCommand $command
     * @return SymfonyCommand|null
     */
    #[Override]
    public function addCommand(callable|SymfonyCommand $command): ?SymfonyCommand
    {
        if ($command instanceof VoyagerCommand) {
            $command->setVenusian($this->venusian);
        }

        return parent::addCommand($command);
    }

    /**
     * Set the container command loader for lazy resolution.
     *
     * @return $this
     */
    public function setContainerCommandLoader(): static
    {
        $this->setCommandLoader(new ContainerCommandLoader($this->venusian, $this->command_map));

        return $this;
    }

    /**
     * The container this console is running inside.
     */
    public function getVenusian(): TheServiceContainer
    {
        return $this->venusian;
    }

    /**
     * The PHP binary, escaped for a shell.
     */
    public static function phpBinary(): string
    {
        return escapeshellarg(php_binary());
    }

    /**
     * The computer binary, escaped for a shell.
     */
    public static function computerBinary(): string
    {
        return escapeshellarg(computer_binary());
    }

    /**
     * A shell string that runs one of our own commands in a fresh process.
     */
    public static function formatCommandString(string $string): string
    {
        return sprintf('%s %s %s', static::phpBinary(), static::computerBinary(), $string);
    }

    /**
     * Register a callback that runs when a console instance is constructed,
     * before its command loader is sealed. This is how providers add commands
     * to the list.
     *
     * @param  \Closure($this): void  $callback
     */
    public static function starting(\Closure $callback): void
    {
        static::$bootstrappers[] = $callback;
    }

    /**
     * Drop the starting callbacks. Tests use this between cases.
     */
    public static function forgetBootstrappers(): void
    {
        static::$bootstrappers = [];
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
     * Parse the incoming Computer command and its input.
     *
     * @param string|Command $command
     * @param array $parameters
     * @return array<string, ArrayInput>
     */
    protected function parseCommand(Command|string $command, array $parameters): array
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
}