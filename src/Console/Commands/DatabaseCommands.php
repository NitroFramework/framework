<?php

namespace Nitro\Console\Commands;

use Nitro\Console\Contracts\CommandInterface;
use Nitro\Console\OutputFormatter;
use Nitro\Database\DB;
use Nitro\Foundation\Contracts\ConfigRepository;

/**
 * Tinker-less database inspection toolkit.
 *
 *   db:show <table> [--limit=10] [--where=col:value …] [--json]
 *     Pretty-print rows as an ASCII table, or one JSON object per line
 *     with --json (handy for piping into jq, etc.).
 *
 *   db:count <table> [--where=col:value …]
 *     Count of matching rows.
 *
 *   db:wipe <table> [--force]
 *     DELETE every row from the table. Refuses in production without
 *     --force, the same guard the destructive migration commands use.
 *
 * Why DELETE and not TRUNCATE? Wider portability — TRUNCATE behaves
 * differently across drivers (transactional vs not, FK enforcement,
 * permission requirements). A DELETE works the same everywhere; for
 * truly massive tables, you can drop+recreate or use a driver-specific
 * tool. Wipe is a developer-affordance, not a production data-mover.
 */
class DatabaseCommands implements CommandInterface
{
    public function __construct(
        private readonly OutputFormatter $output,
        private readonly ConfigRepository $config,
    ) {}

    public function getCommands(): array
    {
        return [
            'db:show'  => 'Show rows from a table (ASCII table or --json)',
            'db:count' => 'Count rows in a table',
            'db:wipe'  => 'Delete every row from a table (--force in production)',
        ];
    }

    public function handle(string $command, array $arguments = []): void
    {
        match ($command) {
            'db:show'  => $this->show($arguments),
            'db:count' => $this->count($arguments),
            'db:wipe'  => $this->wipe($arguments),
            default    => $this->output->error("Unknown db command: {$command}"),
        };
    }

    // ── db:show ───────────────────────────────────────────────────────

    private function show(array $arguments): void
    {
        $table = $this->positional($arguments);
        if (!$table) {
            $this->output->error("Usage: db:show <table> [--limit=10] [--where=col:value] [--json]");
            return;
        }
        $limit  = (int) ($this->flagValue($arguments, '--limit') ?? 10);
        $wheres = $this->parseWheres($arguments);
        $json   = $this->flag($arguments, '--json');

        $query = DB::table($table);
        foreach ($wheres as [$col, $val]) {
            $query->where($col, '=', $val);
        }

        $rows = $query->limit(max(1, $limit))->get()->all();

        if (empty($rows)) {
            $this->output->info("No rows.");
            return;
        }

        $arrayRows = array_map(fn($row) => (array) $row, $rows);

        if ($json) {
            foreach ($arrayRows as $row) {
                $this->output->writeln(json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
            return;
        }

        $this->printAsciiTable($arrayRows);
        $this->output->writeln(sprintf("(%d row(s) — limit=%d)", count($arrayRows), $limit));
    }

    // ── db:count ──────────────────────────────────────────────────────

    private function count(array $arguments): void
    {
        $table = $this->positional($arguments);
        if (!$table) {
            $this->output->error("Usage: db:count <table> [--where=col:value]");
            return;
        }
        $wheres = $this->parseWheres($arguments);

        $query = DB::table($table);
        foreach ($wheres as [$col, $val]) {
            $query->where($col, '=', $val);
        }

        $n = $query->count();
        $this->output->info("{$table}: {$n} row(s)");
    }

    // ── db:wipe ───────────────────────────────────────────────────────

    private function wipe(array $arguments): void
    {
        $table = $this->positional($arguments);
        if (!$table) {
            $this->output->error("Usage: db:wipe <table> [--force]");
            return;
        }

        $env = $this->config->get('app.env');
        if ($env === 'production' && !$this->flag($arguments, '--force')) {
            $this->output->error(
                "Refusing to wipe '{$table}' in production without --force. "
                . "Re-run as: php nitro db:wipe {$table} --force"
            );
            return;
        }

        $deleted = DB::table($table)->delete();
        $this->output->success("Wiped {$table}: {$deleted} row(s) deleted.");
    }

    // ── helpers ───────────────────────────────────────────────────────

    /** First non-flag positional argument. */
    private function positional(array $args): ?string
    {
        foreach ($args as $a) {
            if (!str_starts_with($a, '--')) return $a;
        }
        return null;
    }

    private function flag(array $args, string $flag): bool
    {
        foreach ($args as $a) {
            if ($a === $flag || str_starts_with($a, $flag . '=')) return true;
        }
        return false;
    }

    private function flagValue(array $args, string $flag): ?string
    {
        foreach ($args as $a) {
            if (str_starts_with($a, $flag . '=')) {
                return substr($a, strlen($flag) + 1);
            }
        }
        return null;
    }

    /**
     * Parse all `--where=col:value` flags into [[col, value], ...]. Splits
     * on the first colon so values may contain colons themselves.
     */
    private function parseWheres(array $args): array
    {
        $out = [];
        foreach ($args as $a) {
            if (!str_starts_with($a, '--where=')) continue;
            $body = substr($a, 8);
            $pos = strpos($body, ':');
            if ($pos === false) {
                $this->output->warning("--where missing ':' separator, ignored: {$a}");
                continue;
            }
            $out[] = [substr($body, 0, $pos), substr($body, $pos + 1)];
        }
        return $out;
    }

    /**
     * Render an array of associative arrays as a Markdown-ish ASCII
     * table. Sized to the widest value per column, with `…` truncation
     * past 40 chars so a single 4KB blob can't blow up the layout.
     */
    private function printAsciiTable(array $rows): void
    {
        $columns = array_keys($rows[0]);
        $widths  = array_fill_keys($columns, 0);

        foreach ($columns as $c) $widths[$c] = strlen((string) $c);
        foreach ($rows as $row) {
            foreach ($columns as $c) {
                $val = $this->stringify($row[$c] ?? null);
                $widths[$c] = max($widths[$c], min(strlen($val), 40));
            }
        }

        $renderRow = function (array $cells) use ($columns, $widths) {
            $parts = [];
            foreach ($columns as $c) {
                $parts[] = str_pad($cells[$c] ?? '', $widths[$c]);
            }
            return '| ' . implode(' | ', $parts) . ' |';
        };

        $divider = '+' . implode('+', array_map(fn($c) => str_repeat('-', $widths[$c] + 2), $columns)) . '+';

        $this->output->writeln($divider);
        $this->output->writeln($renderRow(array_combine($columns, $columns)));
        $this->output->writeln($divider);
        foreach ($rows as $row) {
            $cells = [];
            foreach ($columns as $c) {
                $cells[$c] = $this->stringify($row[$c] ?? null);
            }
            $this->output->writeln($renderRow($cells));
        }
        $this->output->writeln($divider);
    }

    private function stringify(mixed $v): string
    {
        if ($v === null) return 'NULL';
        if (is_bool($v)) return $v ? 'true' : 'false';
        $s = is_scalar($v) ? (string) $v : json_encode($v);
        return strlen($s) > 40 ? substr($s, 0, 37) . '…' : $s;
    }
}
