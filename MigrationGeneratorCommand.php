<?php

namespace Voyager\Console;

use Voyager\Filesystem\Filesystem;

use function Voyager\Filesystem\join_paths;

/**
 * A command that writes one migration from a stub of its own: the migration creator names and
 * places the file, then the stub, with its table filled in, becomes the file's contents.
 */
abstract class MigrationGeneratorCommand extends Command
{
    public function __construct(protected Filesystem $files)
    {
        parent::__construct();
    }

    /** The table the migration creates. */
    abstract protected function migrationTableName(): string;

    /** The stub the migration is written from; {{table}} is the table's name. */
    abstract protected function migrationStubFile(): string;

    public function handle(): int
    {
        $table = $this->migrationTableName();

        if ($this->migrationExists($table)) {
            $this->components->error('Migration already exists.');

            return self::FAILURE;
        }

        $this->replaceMigrationPlaceholders($this->createBaseMigration($table), $table);

        $this->components->info('Migration created successfully.');

        return self::SUCCESS;
    }

    protected function createBaseMigration(string $table): string
    {
        return $this->venusian['migration.creator']->create(
            'create_'.$table.'_table', $this->venusian->databasePath('migrations')
        );
    }

    protected function replaceMigrationPlaceholders(string $path, string $table): void
    {
        $this->files->put($path, str_replace('{{table}}', $table, $this->files->get($this->migrationStubFile())));
    }

    protected function migrationExists(string $table): bool
    {
        return count($this->files->glob(
            join_paths($this->venusian->databasePath('migrations'), '*_*_*_*_create_'.$table.'_table.php')
        )) !== 0;
    }
}
