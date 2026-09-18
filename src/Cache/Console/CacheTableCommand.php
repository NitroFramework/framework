<?php

namespace Nitro\Cache\Console;

use Nitro\Console\Contracts\CommandInterface;
use Nitro\Console\OutputFormatter;
use Nitro\Foundation\PathRegistry;

/**
 * Generates the migration for the database cache driver's table.
 *
 * The driver expects `key`, `value` and `expiration`; `expiration` is indexed
 * because garbage collection sweeps on it.
 */
class CacheTableCommand implements CommandInterface
{
    public function __construct(
        private PathRegistry $paths,
        private OutputFormatter $output,
    ) {}

    public function getCommands(): array
    {
        return [
            'make:cache-table' => 'Create a migration for the cache database table',
            'cache:table'      => 'Create a migration for the cache database table',
        ];
    }

    public function handle(string $signature, array $arguments): void
    {
        $directory = $this->paths->base('database/migrations');

        if (glob($directory . '/*_create_cache_table.php') !== []) {
            $this->output->error('A create_cache_table migration already exists.');

            return;
        }

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $filename = date('Y_m_d_His') . '_create_cache_table.php';

        file_put_contents($directory . '/' . $filename, $this->stub());

        $this->output->success("Created: database/migrations/{$filename}");
    }

    private function stub(): string
    {
        return <<<'PHP'
        <?php

        use Nitro\Database\Schema\SchemaBuilder;

        return new class {
            public function up(SchemaBuilder $schema): void
            {
                $schema->create('cache', function ($table) {
                    $table->string('key')->primary();
                    $table->text('value');
                    $table->integer('expiration')->index();
                });
            }

            public function down(SchemaBuilder $schema): void
            {
                $schema->dropIfExists('cache');
            }
        };

        PHP;
    }
}
