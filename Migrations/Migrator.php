<?php

namespace Pingpong\Modules\Migrations;

class Migrator
{
    /**
     * Pingpong Module instance.
     *
     * @var \Pingpong\Modules\Module
     */
    protected $module;

    /**
     * Laravel Application instance.
     *
     * @var \Illuminate\Foundation\Application.
     */
    protected $laravel;

    /**
     * Create new instance.
     *
     * @param \Pingpong\Modules\Module $module
     */
    public function __construct($module)
    {
        $this->module = $module;
        $this->laravel = $module->getLaravel();
    }

    /**
     * Get migration path.
     *
     * @return string
     */
    public function getPath()
    {
        return $this->module->getExtraPath(
            config('modules.paths.generator.migration')
        );
    }

    /**
     * Get migration files.
     *
     * @return array
     */
    public function getMigrations()
    {
        $files = $this->laravel['files']->glob($this->getPath().'/*_*.php');

        // Once we have the array of files in the directory we will just remove the
        // extension and take the basename of the file which is all we need when
        // finding the migrations that haven't been run against the databases.
        if ($files === false) {
            return array();
        }

        $files = array_map(function ($file) {
            return str_replace('.php', '', basename($file));

        }, $files);

        // Once we have all of the formatted file names we will sort them and since
        // they all start with a timestamp this should give us the migrations in
        // the order they were actually created by the application developers.
        sort($files);

        return $files;
    }

    /**
     * Migrate migrations.
     *
     * @return array
     */
    public function migrate()
    {
        $migrations = array_reverse($this->getMigrations());

        $this->requireFiles($migrations);

        // Once we grab all of the migration files for the path, we will compare them
        // against the migrations that have already been run for this package then
        // run each of the outstanding migrations against a database connection.
        $migrations = array_diff($migrations, $this->getRan());

        $migrated = [];

        foreach ($migrations as $migration) {
            $migrated[] = $migration;

            $this->up($migration);

            $this->log($migration);
        }

        return $migrated;
    }

    /**
     * Rollback migration.
     *
     * @return array
     */
    public function rollback()
    {
        $migrations = $this->getLast($this->getMigrations());

        $this->requireFiles($migrations);

        $migrated = [];

        foreach ($migrations as $migration) {
            $data = $this->find($migration);

            if ($data->count()) {
                $migrated[] = $migration;

                $this->down($migration);

                $data->delete();
            }
        }

        return $migrated;
    }

    /**
     * Reset migration.
     *
     * @return array
     */
    public function reset()
    {
        $migrations = $this->getMigrations();

        $this->requireFiles($migrations);

        $migrated = [];

        foreach ($migrations as $migration) {
            $data = $this->find($migration);

            if ($data->count()) {
                $migrated[] = $migration;

                $this->down($migration);

                $data->delete();
            }
        }

        return $migrated;
    }

    /**
     * Run down schema from the given migration name.
     *
     * @param string $migration
     */
    public function down($migration)
    {
        $this->resolve($migration)->down();
    }

    /**
     * Run up schema from the given migration name.
     *
     * @param string $migration
     */
    public function up($migration)
    {
        $this->resolve($migration)->up();
    }

    /**
     * Resolve a migration instance from a file.
     *
     * @param string $file
     *
     * @return object
     */
    public function resolve($file)
    {
        $file = implode('_', array_slice(explode('_', $file), 4));

        $class = studly_case($file);

        return new $class();
    }

    /**
     * Require in all the migration files in a given path.
     *
     * @param array  $files
     */
    public function requireFiles(array $files)
    {
        $path = $this->getPath();

        foreach ($files as $file) {
            $this->laravel['files']->requireOnce($path.'/'.$file.'.php');
        }
    }

    /**
     * Get table instance.
     *
     * @return string
     */
    public function table()
    {
        return $this->laravel['db']->table(config('database.migrations'));
    }

    /**
     * Find migration data from database by given migration name.
     *
     * @param string $migration
     *
     * @return object
     */
    public function find($migration)
    {
        return $this->table()->whereMigration($migration);
    }

    /**
     * Save new migration to database.
     *
     * @param string $migration
     *
     * @return mixed
     */
    public function log($migration)
    {
        return $this->table()->insert([
            'migration' => $migration,
            'batch' => $this->getNextBatchNumber(),
        ]);
    }

    /**
     * Get the next migration batch number.
     *
     * @return int
     */
    public function getNextBatchNumber()
    {
        return $this->getLastBatchNumber() + 1;
    }

    /**
     * Get the last migration batch number.
     *
     * @return int
     */
    public function getLastBatchNumber()
    {
        return $this->table()->max('batch');
    }

    /**
     * Get the last migration batch.
     *
     * @param array $migrations
     *
     * @return array
     */
    public function getLast($migrations)
    {
        $query = $this->table()
            ->where('batch', $this->getLastBatchNumber())
            ->whereIn('migration', $migrations)
            ;

        $result = $query->orderBy('migration', 'desc')->get();

        return collect($result)->map(function ($item) {
            return (array) $item;
        })->lists('migration');
    }

    /**
     * Get the ran migrations.
     *
     * @return array
     */
    public function getRan()
    {
        return $this->table()->lists('migration');
    }
}
