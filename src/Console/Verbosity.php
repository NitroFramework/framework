<?php

namespace Nitro\Console;

/**
 * How much a command is allowed to say.
 *
 * Read from the invocation — `-q`, `-v`, `-vv`, `-vvv` — and checked by the
 * output surface before anything is written, so a command decides what to
 * report and the caller decides how much of it to hear.
 *
 *   $this->line('done');                      // always
 *   $this->line('  ran 4 queries', Verbosity::Verbose);   // only under -v
 */
enum Verbosity: int
{
    /** Errors only; -q. */
    case Quiet = 0;

    /** The default. */
    case Normal = 1;

    /** -v */
    case Verbose = 2;

    /** -vv */
    case VeryVerbose = 3;

    /** -vvv */
    case Debug = 4;

    /** The flags that select each level, longest first so -vvv wins over -v. */
    private const FLAGS = [
        '-vvv'          => self::Debug,
        '--verbose=3'   => self::Debug,
        '-vv'           => self::VeryVerbose,
        '--verbose=2'   => self::VeryVerbose,
        '-v'            => self::Verbose,
        '--verbose'     => self::Verbose,
        '--verbose=1'   => self::Verbose,
        '-q'            => self::Quiet,
        '--quiet'       => self::Quiet,
    ];

    /**
     * The level the given arguments ask for, Normal when they ask for nothing.
     *
     * @param array<int, string> $arguments
     */
    public static function fromArguments(array $arguments): self
    {
        foreach (self::FLAGS as $flag => $level) {
            if (in_array($flag, $arguments, true)) {
                return $level;
            }
        }

        return self::Normal;
    }

    /**
     * The same arguments with the verbosity flags removed.
     *
     * Stripped before a command parses its own signature, or `-v` reads as an
     * unknown option belonging to the command.
     *
     * @param  array<int, string> $arguments
     * @return array<int, string>
     */
    public static function strip(array $arguments): array
    {
        return array_values(array_filter(
            $arguments,
            static fn (string $argument): bool => ! array_key_exists($argument, self::FLAGS)
        ));
    }

    /** Whether output at $level should be shown to someone listening at $this. */
    public function allows(self $level): bool
    {
        return $level->value <= $this->value;
    }
}
