<?php

namespace Voyager\Console;

use Override;
use Throwable;
use Voyager\Contracts\Core\FrameworkCore;
use Voyager\Contracts\Console\Isolatable;
use Voyager\NutsAndBolts\Concerns\Macroable;
use Voyager\Console\View\Components\Factory;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class Command extends SymfonyCommand
{
    use Concerns\CallsCommands,
        Concerns\ConfiguresPrompts,
        Concerns\HasParameters,
        Concerns\InteractsWithIO,
        Concerns\InteractsWithSignals,
        Concerns\PromptsForMissingInput,
        Macroable;

    /**
     * The Venusian application instance. Set when the command runs.
     */
    protected FrameworkCore $venusian;

    /**
     * The name and signature of the console command.
     *
     * @var string|null
     */
    protected ?string $signature = null;

    /**
     * The console command name.
     *
     * @var string|null
     */
    protected ?string $name = null;

    /**
     * The console command description.
     *
     * @var string
     */
    protected string $description = '';

    /**
     * The console command help text.
     *
     * @var string
     */
    protected string $help = '';

    /**
     * Indicates whether the command should be shown in the Computer command list.
     *
     * @var bool
     */
    protected bool $hidden = false;

    /**
     * Indicates whether only one instance of the command can run at any given time.
     *
     * @var bool
     */
    protected bool $isolated = false;

    /**
     * The default exit code for isolated commands.
     *
     * @var self::SUCCESS|self::FAILURE|self::INVALID
     */
    protected int $isolatedExitCode = self::SUCCESS;

    /**
     * The console command name aliases.
     *
     * @var string[]
     */
    protected ?array $aliases = null;

    /**
     * Create a new console command instance.
     */
    public function __construct()
    {
        // We will go ahead and set the name, description, and parameters on console
        // commands just to make things a little easier on the developer. This is
        // so they don't have to all be manually specified in the constructors.
        if (isset($this->signature)) {
            $this->configureUsingFluentDefinition();
        } else {
            parent::__construct($this->name);
        }

        // Once we have constructed the command, we'll set the description and other
        // related properties of the command. If a signature wasn't used to build
        // the command we'll set the arguments and the options on this command.
        if (! empty($this->description)) {
            $this->setDescription($this->description);
        }

        if (! empty($this->help)) {
            $this->setHelp($this->help);
        }

        $this->setHidden($this->isHidden());

        if (isset($this->aliases)) {
            $this->setAliases((array) $this->aliases);
        }

        if (! isset($this->signature)) {
            $this->specifyParameters();
        }

        if ($this instanceof Isolatable) {
            $this->configureIsolation();
        }
    }

    /**
     * Configure the console command using a fluent definition.
     *
     * @return void
     */
    protected function configureUsingFluentDefinition(): void
    {
        [$name, $arguments, $options] = Parser::parse($this->signature);

        parent::__construct($this->name = $name);

        // After parsing the signature we will spin through the arguments and options
        // and set them on this command. These will already be changed into proper
        // instances of these "InputArgument" and "InputOption" Symfony classes.
        $this->getDefinition()->addArguments($arguments);
        $this->getDefinition()->addOptions($options);
    }

    /**
     * Configure the console command for isolation.
     *
     * @return void
     */
    protected function configureIsolation(): void
    {
        $this->getDefinition()->addOption(new InputOption(
            'isolated',
            null,
            InputOption::VALUE_OPTIONAL,
            'Do not run the command if another instance of the command is already running',
            $this->isolated
        ));
    }

    /**
     * Run the console command.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws ExceptionInterface
     */
    #[Override]
    public function run(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output instanceof OutputStyle ? $output : $this->venusian->make(
            OutputStyle::class, ['input' => $input, 'output' => $output]
        );

        $this->components = $this->venusian->make(Factory::class, ['output' => $this->output]);

        $this->configurePrompts($input);

        try {
            return parent::run(
                $this->input = $input, $this->output
            );
        } finally {
            $this->untrap();
        }
    }

    /**
     * Execute the console command.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this instanceof Isolatable && $this->option('isolated') !== false &&
            ! $this->commandIsolationMutex()->create($this)) {
            $this->comment(sprintf(
                'The [%s] command is already running.', $this->getName()
            ));

            return (int) (is_numeric($this->option('isolated'))
                ? $this->option('isolated')
                : $this->isolatedExitCode);
        }

        $method = method_exists($this, 'handle') ? 'handle' : '__invoke';

        try {
            return (int) $this->venusian->call([$this, $method]);
        } catch (ManuallyFailedException $e) {
            $this->components->error($e->getMessage());

            return static::FAILURE;
        } finally {
            if ($this instanceof Isolatable && $this->option('isolated') !== false) {
                $this->commandIsolationMutex()->forget($this);
            }
        }
    }

    /**
     * Get a command isolation mutex instance for the command.
     *
     * @return CommandMutex
     */
    protected function commandIsolationMutex(): CommandMutex
    {
        return $this->venusian->isBound(CommandMutex::class)
            ? $this->venusian->make(CommandMutex::class)
            : $this->venusian->make(CacheCommandMutex::class);
    }

    /**
     * Resolve the console command instance for the given command.
     *
     * @param SymfonyCommand|string  $command
     * @return SymfonyCommand
     */
    protected function resolveCommand($command): SymfonyCommand
    {
        if (is_string($command)) {
            if (! class_exists($command)) {
                return $this->getApplication()->find($command);
            }

            $command = $this->venusian->make($command);
        }

        if ($command instanceof SymfonyCommand) {
            $command->setApplication($this->getApplication());
        }

        if ($command instanceof self) {
            $command->setVenusian($this->getVenusian());
        }

        return $command;
    }

    /**
     * Fail the command manually.
     *
     * @param  Throwable|string|null  $exception
     * @return never
     *
     * @throws ManuallyFailedException|Throwable
     */
    public function fail(Throwable|string|null $exception = null): never
    {
        if (is_null($exception)) {
            $exception = 'Command failed manually.';
        }

        if (is_string($exception)) {
            $exception = new ManuallyFailedException($exception);
        }

        throw $exception;
    }

    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    #[Override]
    public function isHidden(): bool
    {
        return $this->hidden;
    }

    /**
     * {@inheritdoc}
     */
    #[Override]
    public function setHidden(bool $hidden = true): static
    {
        parent::setHidden($this->hidden = $hidden);

        return $this;
    }

    /**
     * Get the Venusian application instance.
     *
     * @return FrameworkCore
     */
    // Untyped: mirrors $venusian, which callers set to a Vessel,
    // a System\Application, or a mocked Console\Application.
    public function getVenusian(): FrameworkCore
    {
        return $this->venusian;
    }

    /**
     * Set the Venusian application instance.
     *
     * @param FrameworkCore $venusian
     * @return void
     */
    public function setVenusian(FrameworkCore $venusian): void
    {
        $this->venusian = $venusian;
    }
}
