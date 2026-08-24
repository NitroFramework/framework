<?php

namespace Nitro\Console\Commands;

use Nitro\Console\Contracts\CommandInterface;
use Nitro\Console\OutputFormatter;
use Nitro\Foundation\PathRegistry;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * `nitro audit:variables` — find variables whose names don't say what they hold.
 *
 * A name like $c costs a reader a trip through the surrounding code every time
 * they meet it, and it means something different in every file: in this codebase
 * $c has been a container, a column, a cipher and a character. This walks the
 * source and writes a report of every short or non-descriptive name, grouped so
 * each one can be judged in context rather than renamed blindly.
 *
 * The scan runs on TOKENS, not a regex, so a "$c" that is only string data (the
 * container compiler emits generated source containing it) is never counted, and
 * a variable interpolated into a double-quoted string always is.
 *
 * Names that are already clear are left out of the report by default: $id, $ip,
 * $to, $ok and friends are short because the word is short, which is not the
 * problem being looked for. Pass --all to include them.
 */
class VariableAuditCommand implements CommandInterface
{
    /**
     * Short names that are nonetheless the clearest available word for the
     * thing, so flagging them would be noise. $iv is the standard name in
     * cryptographic code; $id and $ip are ordinary English.
     */
    private const ALREADY_CLEAR = ['id', 'ip', 'to', 'ok', 'iv', 'ns', 'db', 'os', 'up'];

    /** Suggested replacements for the names that come up again and again. */
    private const COMMON_INTENT = [
        'e'  => 'exception (in a catch), or element',
        'i'  => 'index',
        'k'  => 'key',
        'v'  => 'value',
        'c'  => 'ambiguous — container / column / cipher / char, check each site',
        'm'  => 'ambiguous — match / modifiers / method / message',
        'p'  => 'ambiguous — path / param / property / process',
        'a'  => 'ambiguous — array / attribute / anchor',
        'r'  => 'result / response / row',
        's'  => 'string / style / store',
        'n'  => 'count / number',
        'f'  => 'file / callback',
        't'  => 'text / token / timer',
        'b'  => 'buffer / builder / bag',
        'o'  => 'object / option',
        'cb' => 'callback',
        'fn' => 'callback',
        'co' => 'coroutine',
        'ch' => 'channel / character',
        'fg' => 'foreground',
        'bg' => 'background',
        'qs' => 'queryString',
        'fk' => 'foreignKey',
        'eq' => 'equals / operator',
        'ms' => 'milliseconds',
        'wg' => 'waitGroup',
        '_'  => 'unused — name it or drop it',
    ];

    public function __construct(
        private PathRegistry $paths,
        private OutputFormatter $output,
    ) {}

    public function getCommands(): array
    {
        return [
            'audit:variables' => 'Report short/non-descriptive variable names and write them to a file',
        ];
    }

    public function handle(string $signature, array $arguments): void
    {
        $options = $this->parseArguments($arguments);

        $roots = $options['paths'] ?: [$this->defaultRoot()];
        $report = $this->scan($roots, $options['max'], $options['all']);

        if ($report['total'] === 0) {
            $this->output->success("No variables of {$options['max']} characters or fewer found. Nothing to do.");
            return;
        }

        $written = $this->write($report, $options['out'], $options['max'], $roots);

        $this->summarise($report, $written, $options['max']);
    }

    // ─── Scanning ───────────────────────────────────────────────────────────

    /**
     * Walk the roots and collect every short variable occurrence.
     *
     * @return array{names: array, total: int, files: int}
     */
    private function scan(array $roots, int $max, bool $includeClear): array
    {
        $names = [];
        $total = 0;
        $files = 0;

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                $this->output->warning("Skipping [{$root}] — not a directory.");
                continue;
            }

            foreach ($this->phpFiles($root) as $file) {
                $files++;
                $total += $this->scanFile($file, $max, $includeClear, $names);
            }
        }

        // Busiest name first — that is the one worth fixing first.
        uasort($names, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);

        return ['names' => $names, 'total' => $total, 'files' => $files];
    }

    /** Collect occurrences from one file into $names (by reference). */
    private function scanFile(SplFileInfo $file, int $max, bool $includeClear, array &$names): int
    {
        $source = @file_get_contents($file->getPathname());

        if ($source === false) {
            return 0;
        }

        $path = $this->relative($file->getPathname());
        $found = 0;

        foreach (token_get_all($source) as $token) {
            if (! is_array($token) || $token[0] !== T_VARIABLE) {
                continue;
            }

            $name = ltrim($token[1], '$');

            if ($name === 'this' || mb_strlen($name) > $max) {
                continue;
            }

            if (! $includeClear && in_array($name, self::ALREADY_CLEAR, true)) {
                continue;
            }

            $names[$name] ??= ['count' => 0, 'files' => [], 'intent' => self::COMMON_INTENT[$name] ?? ''];
            $names[$name]['count']++;
            $names[$name]['files'][$path][] = $token[2]; // line number
            $found++;
        }

        return $found;
    }

    /** Every .php file under a root, vendor and node_modules excluded. */
    private function phpFiles(string $root): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());

            if (str_contains($path, '/vendor/') || str_contains($path, '/node_modules/')) {
                continue;
            }

            yield $file;
        }
    }

    // ─── Reporting ──────────────────────────────────────────────────────────

    /** Write the report and return the path it landed at. */
    private function write(array $report, string $out, int $max, array $roots): string
    {
        $lines = [];
        $lines[] = 'Variable name audit';
        $lines[] = str_repeat('=', 60);
        $lines[] = 'Scanned:     ' . implode(', ', array_map([$this, 'relative'], $roots));
        $lines[] = 'Files:       ' . $report['files'];
        $lines[] = 'Threshold:   names of ' . $max . ' character(s) or fewer';
        $lines[] = 'Occurrences: ' . $report['total'] . ' across ' . count($report['names']) . ' distinct names';
        $lines[] = '';
        $lines[] = 'Names already clear enough to skip: $' . implode(', $', self::ALREADY_CLEAR);
        $lines[] = '(pass --all to include them)';
        $lines[] = '';
        $lines[] = 'A name is worth changing when a reader has to look elsewhere to learn';
        $lines[] = 'what it holds. Where the suggestion below says "ambiguous", the same';
        $lines[] = 'name means different things in different files — those need reading,';
        $lines[] = 'not a find-and-replace.';
        $lines[] = '';

        foreach ($report['names'] as $name => $data) {
            $lines[] = str_repeat('-', 60);
            $lines[] = sprintf(
                '$%s — %d occurrence(s) in %d file(s)%s',
                $name,
                $data['count'],
                count($data['files']),
                $data['intent'] !== '' ? '   [' . $data['intent'] . ']' : ''
            );
            $lines[] = '';

            // Densest file first: that is where the name is load-bearing.
            $files = $data['files'];
            uasort($files, static fn(array $a, array $b): int => count($b) <=> count($a));

            foreach ($files as $path => $occurrences) {
                $lines[] = sprintf(
                    '  %-58s %3d  lines %s',
                    $path,
                    count($occurrences),
                    $this->summariseLines($occurrences)
                );
            }

            $lines[] = '';
        }

        $path = $this->resolveOutPath($out);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL);

        return $path;
    }

    /** Compact a line-number list: 12, 14, 15, 16, 40 -> "12, 14-16, 40". */
    private function summariseLines(array $lines): string
    {
        $lines = array_values(array_unique($lines));
        sort($lines);

        $ranges = [];
        $start = $previous = array_shift($lines);

        foreach ($lines as $line) {
            if ($line === $previous + 1) {
                $previous = $line;
                continue;
            }
            $ranges[] = $start === $previous ? (string) $start : "{$start}-{$previous}";
            $start = $previous = $line;
        }

        $ranges[] = $start === $previous ? (string) $start : "{$start}-{$previous}";

        // Long lists help nobody; the file path is enough to go on from there.
        if (count($ranges) > 12) {
            $ranges = array_slice($ranges, 0, 12);
            $ranges[] = '…';
        }

        return implode(', ', $ranges);
    }

    /** Print the headline numbers to the terminal. */
    private function summarise(array $report, string $written, int $max): void
    {
        $this->output->writeln('');
        $this->output->info(sprintf(
            'Scanned %d file(s): %d occurrence(s) of %d short name(s).',
            $report['files'],
            $report['total'],
            count($report['names'])
        ));
        $this->output->writeln('');

        $shown = 0;
        foreach ($report['names'] as $name => $data) {
            if ($shown++ >= 15) {
                $this->output->writeln(sprintf('  … and %d more', count($report['names']) - 15));
                break;
            }

            $this->output->writeln(sprintf(
                '  %-6s %5d in %2d file(s)   %s',
                '$' . $name,
                $data['count'],
                count($data['files']),
                $data['intent']
            ));
        }

        $this->output->writeln('');
        $this->output->success("Report written to {$written}");
    }

    // ─── Arguments ──────────────────────────────────────────────────────────

    /**
     * --max=N    treat names of N characters or fewer as short (default 2)
     * --out=PATH where to write the report
     * --all      include names the command normally considers clear enough
     * <path>...  directories to scan (default: the framework/app source root)
     */
    private function parseArguments(array $arguments): array
    {
        $options = ['max' => 2, 'out' => '', 'all' => false, 'paths' => []];

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--max=')) {
                $options['max'] = max(1, (int) substr($argument, 6));
            } elseif (str_starts_with($argument, '--out=')) {
                $options['out'] = substr($argument, 6);
            } elseif ($argument === '--all') {
                $options['all'] = true;
            } elseif (! str_starts_with($argument, '--')) {
                $options['paths'][] = $argument;
            }
        }

        return $options;
    }

    /** Where to scan when no path was given: the app's source, else the framework's. */
    private function defaultRoot(): string
    {
        $appSource = $this->paths->base('app');

        return is_dir($appSource) ? $appSource : dirname(__DIR__, 2);
    }

    private function resolveOutPath(string $out): string
    {
        if ($out !== '') {
            return $out;
        }

        return $this->paths->storage('variable-audit.txt');
    }

    /** Trim the project root off a path so the report reads cleanly. */
    private function relative(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $base = str_replace('\\', '/', $this->paths->base());

        return str_starts_with($path, $base) ? ltrim(substr($path, strlen($base)), '/') : $path;
    }
}
