<?php

namespace Voyager\Console\Scheduling;

use Voyager\Console\Command;
use Voyager\Console\Events\ScheduledBackgroundTaskFinished;
use Voyager\Contracts\Events\Dispatcher;
use Voyager\NutsAndBolts\Collection;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'schedule:finish')]
class ScheduleFinishCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected ?string $signature = 'schedule:finish {id} {code=0}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected string $description = 'Handle the completion of a scheduled command';

    /**
     * Indicates whether the command should be shown in the Computer command list.
     *
     * @var bool
     */
    protected bool $hidden = true;

    /**
     * Execute the console command.
     *
     * @param  \Voyager\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    public function handle(Schedule $schedule)
    {
        (new Collection($schedule->events()))
            ->filter(fn ($value) => $value->mutexName() == $this->argument('id'))
            ->each(function ($event) {
                $event->finish($this->venusian, $this->argument('code'));

                $this->venusian->make(Dispatcher::class)->dispatch(new ScheduledBackgroundTaskFinished($event));
            });
    }
}
