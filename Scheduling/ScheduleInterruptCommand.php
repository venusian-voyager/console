<?php

namespace Voyager\Console\Scheduling;

use Voyager\Console\Command;
use Voyager\Contracts\Cache\Repository as Cache;
use Voyager\NutsAndBolts\MagicAliases\Date;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'schedule:interrupt')]
class ScheduleInterruptCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected ?string $name = 'schedule:interrupt';

    /**
     * The console command description.
     *
     * @var string
     */
    protected string $description = 'Interrupt the current schedule run';

    /**
     * The cache store implementation.
     *
     * @var \Voyager\Contracts\Cache\Repository
     */
    protected ?Cache $cache = null;

    /**
     * Create a new schedule interrupt command.
     *
     * @param  \Voyager\Contracts\Cache\Repository  $cache
     */
    public function __construct(Cache $cache)
    {
        parent::__construct();

        $this->cache = $cache;
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $this->cache->put('illuminate:schedule:interrupt', true, Date::now()->endOfMinute());

        $this->components->info('Broadcasting schedule interrupt signal.');
    }
}
