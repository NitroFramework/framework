<?php

namespace Nitro\Session\Console;

use Nitro\Console\ExitCode;
use Nitro\Console\Contracts\CommandInterface;
use Nitro\Console\OutputFormatter;
use Nitro\Foundation\Contracts\PathRegistry;

/**
 * Generates the migration for the database session driver's table.
 *
 * The driver expects `id`, `payload` and `last_activity`; `last_activity` is
 * indexed because garbage collection sweeps on it.
 */
class SessionTableCommand implements CommandInterface
{
    public function __construct(
        private PathRegistry $paths,
        private OutputFormatter $output,
    ) {}

    /**
     * Signature => description, as a constant so the manager can read it
     * without constructing the command.
     *
     * @var array<string, string>
     */
    public const COMMANDS = [
            'make:session-table' => 'Create a migration for the session database table',
            'session:table'      => 'Create a migration for the session database table',
        ];

    public function getCommands(): array
    {
        return self::COMMANDS;
    }

    public function handle(string $signature, array $arguments): int
    {
        $directory = $this->paths->base('database/migrations');

        if ($this->migrationExists($directory)) {
            $this->output->error('A create_sessions_table migration already exists.');

            return ExitCode::FAILURE;
        }

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $filename = date('Y_m_d_His') . '_create_sessions_table.php';

        file_put_contents($directory . '/' . $filename, $this->stub());

        $this->output->success("Created: database/migrations/{$filename}");

        return ExitCode::SUCCESS;
    }

    /** Determine whether the table's migration has already been generated. */
    private function migrationExists(string $directory): bool
    {
        return glob($directory . '/*_create_sessions_table.php') !== [];
    }

    private function stub(): string
    {
        return <<<'PHP'
        <?php

        use Nitro\Database\Schema\SchemaBuilder;

        return new class {
            public function up(SchemaBuilder $schema): void
            {
                $schema->create('sessions', function ($table) {
                    $table->string('id')->primary();
                    $table->text('payload');
                    $table->integer('last_activity')->index();
                });
            }

            public function down(SchemaBuilder $schema): void
            {
                $schema->dropIfExists('sessions');
            }
        };

        PHP;
    }
}
