<?php

namespace Voyager\Console;

use Carbon\CarbonInterval;
use Voyager\Cache\DynamoDbStore;
use Voyager\Contracts\Cache\Factory as Cache;
use Voyager\Contracts\Cache\LockProvider;
use Voyager\NutsAndBolts\Concerns\InteractsWithTime;

class CacheCommandMutex implements CommandMutex
{
    use InteractsWithTime;

    /**
     * The cache factory implementation.
     *
     * @var \Voyager\Contracts\Cache\Factory
     */
    public ?Cache $cache = null;

    /**
     * The cache store that should be used.
     *
     * @var string|null
     */
    public ?string $store = null;

    /**
     * Create a new command mutex.
     *
     * @param  \Voyager\Contracts\Cache\Factory  $cache
     */
    public function __construct(Cache $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Attempt to obtain a command mutex for the given command.
     *
     * @param  \Voyager\Console\Command  $command
     * @return bool
     */
    public function create($command): bool
    {
        $store = $this->cache->store($this->store);

        $expiresAt = method_exists($command, 'isolationLockExpiresAt')
            ? $command->isolationLockExpiresAt()
            : CarbonInterval::hour();

        if ($this->shouldUseLocks($store->getStore())) {
            return $store->getStore()->lock(
                $this->commandMutexName($command),
                $this->secondsUntil($expiresAt)
            )->get();
        }

        return $store->add($this->commandMutexName($command), true, $expiresAt);
    }

    /**
     * Determine if a command mutex exists for the given command.
     *
     * @param  \Voyager\Console\Command  $command
     * @return bool
     */
    public function exists($command): bool
    {
        $store = $this->cache->store($this->store);

        if ($this->shouldUseLocks($store->getStore())) {
            $lock = $store->getStore()->lock($this->commandMutexName($command));

            return tap(! $lock->get(), function ($exists) use ($lock) {
                if ($exists) {
                    $lock->release();
                }
            });
        }

        return $this->cache->store($this->store)->has($this->commandMutexName($command));
    }

    /**
     * Release the mutex for the given command.
     *
     * @param  \Voyager\Console\Command  $command
     * @return bool
     */
    public function forget($command): bool
    {
        $store = $this->cache->store($this->store);

        if ($this->shouldUseLocks($store->getStore())) {
            return $store->getStore()->lock($this->commandMutexName($command))->forceRelease();
        }

        return $this->cache->store($this->store)->forget($this->commandMutexName($command));
    }

    /**
     * Get the isolatable command mutex name.
     *
     * @param  \Voyager\Console\Command  $command
     * @return string
     */
    protected function commandMutexName($command): string
    {
        $baseName = 'framework'.DIRECTORY_SEPARATOR.'command-'.$command->getName();

        return method_exists($command, 'isolatableId')
            ? $baseName.'-'.$command->isolatableId()
            : $baseName;
    }

    /**
     * Specify the cache store that should be used.
     *
     * @param  string|null  $store
     * @return $this
     */
    public function useStore($store): static
    {
        $this->store = $store;

        return $this;
    }

    /**
     * Determine if the given store should use locks for command mutexes.
     *
     * @param  \Voyager\Contracts\Cache\Store  $store
     * @return bool
     */
    protected function shouldUseLocks($store): bool
    {
        return $store instanceof LockProvider && ! $store instanceof DynamoDbStore;
    }
}
