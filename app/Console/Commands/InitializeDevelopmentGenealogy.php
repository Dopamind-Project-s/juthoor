<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Initializes only a demonstrably empty, local, disposable target; never drops any table. */
class InitializeDevelopmentGenealogy extends Command
{
    protected $signature = 'genealogy:initialize-development';

    protected $description = 'Verify an empty local database, migrate it and seed the unified genealogy proposal';

    public function handle(): int
    {
        $connection = config('database.default');
        $settings = config('database.connections.'.$connection);
        $driver = $settings['driver'];
        $database = $settings['database'];
        $local = $driver === 'sqlite'
            ? ($database === ':memory:' || str_starts_with(realpath($database) ?: '', realpath(database_path()).DIRECTORY_SEPARATOR))
            : ($driver === 'pgsql' && in_array($settings['host'], ['localhost', '127.0.0.1', '::1'], true) && str_starts_with($database, 'juthoor_test_'));
        if (! app()->environment(['local', 'testing']) || ! $local || filled($settings['url'] ?? null)) {
            $this->error('Refused: expected a local/testing disposable SQLite file or dedicated juthoor_test_* PostgreSQL database without DB_URL.');

            return self::FAILURE;
        }
        $this->line('Environment: '.app()->environment().'; driver: '.$driver.'; database: '.$database);
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if ($table !== 'migrations' && DB::table($table)->exists()) {
                $this->error('Refused: database is not empty ('.$table.'). Use db:seed for an idempotent rerun; inspect any existing data.');

                return self::FAILURE;
            }
        }
        if ($this->call('migrate', ['--no-interaction' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        return $this->call('db:seed', ['--no-interaction' => true]);
    }
}
