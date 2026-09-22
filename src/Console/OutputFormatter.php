<?php

namespace Nitro\Console;

use Nitro\Console\Support\Style;

/**
 * Output formatter for console commands.
 * 
 * Provides methods for formatting and colorizing console output.
 * Uses ANSI color codes to enhance the user experience in the terminal.
 * 
 * @package Nitro\Console
 * @author Zeeshan Ali
 * @version 1.0 
 * 
 */
class OutputFormatter
{
    /**
     * ANSI color codes for terminal output formatting.
     * 
     * @var array<string, string>
     */
    const COLORS = [
        'red' => "\033[31m",
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'magenta' => "\033[35m",
        'cyan' => "\033[36m",
        'white' => "\033[37m",
        'bold' => "\033[1m",
        'reset' => "\033[0m"
    ];

    /**
     * Apply color formatting to text using ANSI codes.
     * 
     * @param string $text The text to colorize
     * @param string $color The color name from the COLORS array
     * @param bool $bold Whether to make the text bold
     * @return string The formatted text with ANSI color codes
     */
    /**
     * Whether output is going somewhere that can render colour.
     *
     * Null until first asked, and answered then rather than in the
     * constructor: this class is built without one in places — a test naming
     * a command's dependencies it does not intend to use — and a typed
     * property with no value is a fatal the moment anything prints.
     */
    private ?bool $decorated = null;

    public function __construct(?bool $decorated = null)
    {
        $this->decorated = $decorated;
    }

    /** Force colour on or off, whatever the terminal says. */
    public function setDecorated(bool $decorated): void
    {
        $this->decorated = $decorated;
    }

    public function color(string $text, string $color, bool $bold = false): string
    {
        $this->decorated ??= (new Style())->isDecorated();

        // Escape sequences in a redirected file are noise nobody asked for:
        // `nitro migrate > deploy.log` should leave a log that reads.
        if (! $this->decorated) {
            return $text;
        }

        $colorCode = self::COLORS[$color] ?? '';
        $boldCode = $bold ? self::COLORS['bold'] : '';
        $resetCode = self::COLORS['reset'];
        return $colorCode . $boldCode . $text . $resetCode;
    }

    /**
     * Output a success message in green.
     * 
     * @param string $message The success message to display
     * @return void
     */
    public function success(string $message): void
    {
        echo $this->color($message, 'green') . "\n";
    }

    /**
     * Output an error message in red.
     * 
     * @param string $message The error message to display
     * @return void
     */
    public function error(string $message): void
    {
        echo $this->color($message, 'red') . "\n";
    }

    /**
     * Output an informational message in cyan.
     * 
     * @param string $message The info message to display
     * @return void
     */
    public function info(string $message): void
    {
        echo $this->color($message, 'cyan') . "\n";
    }

    /**
     * Output a warning message in yellow.
     * 
     * @param string $message The warning message to display
     * @return void
     */
    public function warning(string $message): void
    {
        echo $this->color($message, 'yellow') . "\n";
    }

    /**
     * Output text without formatting or newline.
     * 
     * @param string $message The message to display
     * @return void
     */
    public function write(string $message): void
    {
        echo $message;
    }

    /**
     * Output text without formatting but with a newline.
     * 
     * @param string $message The message to display
     * @return void
     */
    public function writeln(string $message): void
    {
        echo $message . "\n";
    }
}
