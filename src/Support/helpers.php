<?php

// Generated from src/Support/Helpers/ by `composer helpers`. Do not edit:
// change the file there and regenerate. Each file keeps its own namespace
// block, so the classes one imports cannot clash with another's.

// ── profile.php ─────────────────────────────────────────────────────

namespace {

if (! defined('NITRO_PROFILE')) {
    /**
     * Whether any timing instrumentation runs in this process.
     *
     * True when NITRO_PROFILE or NITRO_TIMELINE is set in the real environment,
     * or when the request asks for ?timeline. Every BootProfile and Timeline
     * call site checks this first, so with it false neither class loads and
     * no mark's label is built.
     *
     * Decided here because this file loads before anything else, which the
     * first mark needs. That is also why .env cannot switch it: .env is read
     * during bootstrap, after the first mark.
     */
    define('NITRO_PROFILE', (static function (): bool {
        foreach (['NITRO_PROFILE', 'NITRO_TIMELINE'] as $name) {
            $flag = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

            if ($flag !== false && $flag !== null && $flag !== '' && $flag !== '0' && $flag !== 'false') {
                return true;
            }
        }

        return isset($_GET['timeline']);
    })());
}

}

// ── app.php ─────────────────────────────────────────────────────────

namespace {

use Nitro\Container\Container;

if (!function_exists('app')) {
    /**
     * The service container, or a resolved service from it.
     *
     *   app()                 // the container itself
     *   app(UserRepo::class)  // resolve a binding, or auto-wire it
     *   app('paths')          // resolve by alias
     *
     * `app()` is the container (as in Laravel) — the canonical way to reach it
     * from OUTSIDE a class that has one. Inside a class that holds a container,
     * use it: `$this->container->resolve(...)`. That split is the rule
     * the framework's own code follows — a global lookup where there is nothing
     * to inject (helpers, compiled Blade output, static entry points), the
     * injected container everywhere else.
     *
     * Resolution goes through resolve(), so an unbound-but-constructible
     * class is auto-wired rather than throwing.
     *
     * The Application is a distinct object; ask for it by name when you need it:
     * app(\Nitro\Foundation\Application::class).
     *
     * @param string|null $abstract Service to resolve, or null for the container.
     * @return mixed
     */
    function app(?string $abstract = null)
    {
        $container = Container::getInstance();

        if ($abstract === null) {
            return $container;
        }

        return $container->resolve($abstract);
    }
}

}

// ── config.php ──────────────────────────────────────────────────────

namespace {

if (!function_exists('env')) {
    /**
     * Get environment variable with optional default
     * 
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function env(string $key, $default = null)
    {
        $value = $_ENV[$key] ?? getenv($key);

        if ($value === false) {
            return $default;
        }

        // Convert string representations to actual types
        switch (strtolower($value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'empty':
            case '(empty)':
                return '';
            case 'null':
            case '(null)':
                return null;
        }

        // Remove quotes if present
        if (strlen($value) > 1 && $value[0] === '"' && $value[-1] === '"') {
            return substr($value, 1, -1);
        }

        return $value;
    }
}

if (!function_exists('config')) {
    /**
     * Get configuration value from the config service
     * 
     * @param string|null $key Configuration key (dot notation supported)
     * @param mixed $default Default value if key not found
     * @return mixed
     */
    function config(?string $key = null, $default = null)
    {
        $config = app('config');
        
        // If no key provided, return the entire config instance
        if ($key === null) {
            return $config;
        }
        
        // Get specific config value
        return $config->get($key, $default);
    }
}

}

// ── path.php ────────────────────────────────────────────────────────

namespace {

if (!function_exists('app_path')) {
    /**
     * Get the path to the application (app/) directory
     * 
     * @param string $path
     * @return string
     */
    function app_path(string $path = ''): string
    {
        return base_path('app' . ($path ? '/' . $path : ''));
    }
}

if (!function_exists('base_path')) {
    /**
     * Get the path to the application base directory
     * 
     * @param string $path
     * @return string
     */
    function base_path(string $path = ''): string
    {
        return app('paths')->base($path);
    }
}

if (!function_exists('config_path')) {
    /**
     * Get the path to the config directory
     * 
     * @param string $path
     * @return string
     */
    function config_path(string $path = ''): string
    {
        return app('paths')->config($path);
    }
}

if (!function_exists('public_path')) {
    /**
     * Get the path to the public directory
     * 
     * @param string $path
     * @return string
     */
    function public_path(string $path = ''): string
    {
        return app('paths')->public($path);
    }
}

if (!function_exists('resource_path')) {
    /**
     * Get the path to the resources directory
     * 
     * @param string $path
     * @return string
     */
    function resource_path(string $path = ''): string
    {
        return app('paths')->resources($path);
    }
}

if (!function_exists('view_path')) {
    /**
     * Get the path to the views directory
     * 
     * @param string $path
     * @return string
     */
    function view_path(string $path = ''): string
    {
        return app('paths')->views($path);
    }
}

if (!function_exists('storage_path')) {
    /**
     * Get the path to the storage directory
     * 
     * @param string $path
     * @return string
     */
    function storage_path(string $path = ''): string
    {
        return app('paths')->storage($path);
    }
}

if (!function_exists('cache_path')) {
    /**
     * Get the path to the cache directory
     * 
     * @param string $path
     * @return string
     */
    function cache_path(string $path = ''): string
    {
        return app('paths')->cache($path);
    }
}

if (!function_exists('database_path')) {
    /**
     * Get the path to the database directory
     * 
     * @param string $path
     * @return string
     */
    function database_path(string $path = ''): string
    {
        return app('paths')->database($path);
    }
}

if (!function_exists('migrations_path')) {
    /**
     * Get the path to the migrations directory
     * 
     * @param string $path
     * @return string
     */
    function migrations_path(string $path = ''): string
    {
        return app('paths')->migrations($path);
    }
}

}

// ── array.php ───────────────────────────────────────────────────────

namespace {

/**
 * Array Helper Functions
 * 
 * Provides utility functions for working with arrays.
 * 
 * @package Nitro\Support
 * @author Zeeshan Ali 
 * @version 1.0
 */


if (!function_exists('array_get')) {
    /**
     * Get an item from an array using dot notation
     * 
     * @param array $array
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function array_get(array $array, string $key, $default = null)
    {
        if (isset($array[$key])) {
            return $array[$key];
        }

        foreach (explode('.', $key) as $segment) {
            if (is_array($array) && array_key_exists($segment, $array)) {
                $array = $array[$segment];
            } else {
                return $default;
            }
        }

        return $array;
    }
}

if (!function_exists('array_set')) {
    /**
     * Set an array item using dot notation
     * 
     * @param array $array
     * @param string $key
     * @param mixed $value
     * @return array
     */
    function array_set(array &$array, string $key, $value): array
    {
        $keys = explode('.', $key);

        while (count($keys) > 1) {
            $key = array_shift($keys);

            if (!isset($array[$key]) || !is_array($array[$key])) {
                $array[$key] = [];
            }

            $array = &$array[$key];
        }

        $array[array_shift($keys)] = $value;

        return $array;
    }
}

if (!function_exists('array_has')) {
    /**
     * Check if array has key using dot notation
     * 
     * @param array $array
     * @param string $key
     * @return bool
     */
    function array_has(array $array, string $key): bool
    {
        foreach (explode('.', $key) as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return false;
            }
            $array = $array[$segment];
        }

        return true;
    }
}

if (!function_exists('array_only')) {
    /**
     * Get only specified keys from array
     * 
     * @param array $array
     * @param array $keys
     * @return array
     */
    function array_only(array $array, array $keys): array
    {
        return array_intersect_key($array, array_flip($keys));
    }
}

if (!function_exists('array_except')) {
    /**
     * Get array without specified keys
     * 
     * @param array $array
     * @param array $keys
     * @return array
     */
    function array_except(array $array, array $keys): array
    {
        return array_diff_key($array, array_flip($keys));
    }
}

}

// ── collection.php ──────────────────────────────────────────────────

namespace {

/**
 * Collection Helper Functions
 * 
 * Provides utility functions for working with collections.
 * 
 * @package Nitro\Support
 * @author Zeeshan Ali 
 * @version 1.0
 */



if (!function_exists('collect')) {
    /**
     * Create a Collection from the given items.
     */
    function collect(iterable $items = []): \Nitro\Support\Collection
    {
        return new \Nitro\Support\Collection(
            is_array($items) ? $items : iterator_to_array($items)
        );
    }
}

if (!function_exists('transform')) {
    /**
     * Transform a value if it's not null
     * 
     * @param mixed $value
     * @param callable $callback
     * @param mixed $default
     * @return mixed
     */
    function transform($value, callable $callback, $default = null)
    {
        if ($value !== null) {
            return $callback($value);
        }

        return $default;
    }
}

if (!function_exists('with')) {
    /**
     * Return the given value, optionally passed through a callback
     * 
     * @param mixed $value
     * @param callable|null $callback
     * @return mixed
     */
    function with($value, ?callable $callback = null)
    {
        return $callback ? $callback($value) : $value;
    }
}

if (!function_exists('tap')) {
    /**
     * Call the given callback with the given value, then return the value
     * 
     * @param mixed $value
     * @param callable $callback
     * @return mixed
     */
    function tap($value, callable $callback)
    {
        $callback($value);
        return $value;
    }
}

}

// ── conditional.php ─────────────────────────────────────────────────

namespace {

/**
 * Conditional Helper Functions
 * 
 * Provides utility functions for conditional operations.
 * 
 * @package Nitro\Support
 * @author Zeeshan Ali 
 * @version 1.0
 */


if (!function_exists('when')) {
    /**
     * Execute callback when condition is true
     * 
     * @param bool $condition
     * @param callable $callback
     * @param callable|null $default
     * @return mixed
     */
    function when(bool $condition, callable $callback, ?callable $default = null)
    {
        if ($condition) {
            return $callback();
        }

        return $default ? $default() : null;
    }
}

if (!function_exists('unless')) {
    /**
     * Execute callback when condition is false
     * 
     * @param bool $condition
     * @param callable $callback
     * @param callable|null $default
     * @return mixed
     */
    function unless(bool $condition, callable $callback, ?callable $default = null)
    {
        return when(!$condition, $callback, $default);
    }
}

if (!function_exists('optional')) {
    /**
     * Provide access to optional objects
     * 
     * @param mixed $value
     * @param callable|null $callback
     * @return mixed
     */
    function optional($value = null, ?callable $callback = null)
    {
        if ($value === null) {
            return new class {
                public function __call($method, $args)
                {
                    return null;
                }
                public function __get($key)
                {
                    return null;
                }
                public function __set($key, $value)
                {
                    return null;
                }
                public function __isset($key)
                {
                    return false;
                }
                public function __unset($key)
                {
                    return null;
                }
            };
        }

        return $callback ? $callback($value) : $value;
    }
}

}

// ── debug.php ───────────────────────────────────────────────────────

namespace {

use Nitro\Debug\Dumper;

if (!function_exists('dd')) {
    /**
     * Dump and die - useful for debugging
     * 
     * @param mixed ...$vars
     * @return void
     */
    function dd(...$vars): void
    {
        echo '<pre style="
        background: #1a202c; 
        color: #e2e8f0; 
        padding: 20px; 
        border-radius: 8px; 
        margin: 20px; 
        font-family: monospace; 
        font-size: 14px; 
        line-height: 1.5;
        overflow-x: auto;
    ">';

        echo '<div style="color: #ff6b35; font-weight: bold; margin-bottom: 10px;">🚀 NitroPHP Debug Output</div>';

        foreach ($vars as $i => $var) {
            if (count($vars) > 1) {
                echo '<div style="color: #f6ad55; font-weight: bold; margin-top:10px;">Variable #' . ($i + 1) . ':</div>';
            }

            echo highlight_var($var);

            if ($i < count($vars) - 1) {
                echo '<div style="border-top: 1px dashed #4a5568; margin: 15px 0;"></div>';
            }
        }

        echo '</pre>';
        exit;
    }
}



if (!function_exists('highlight_var')) {
    /**
     * Highlight variable output with colors
     * 
     * @param mixed $var
     * @return string
     */
    function highlight_var($var): string
    {
        if (is_array($var)) {
            $output = "Array(" . count($var) . ") {\n";
            foreach ($var as $key => $value) {
                $output .= "    [" . htmlspecialchars((string) $key) . "] => " . trim(highlight_var($value)) . "\n";
            }
            $output .= "}";
            return "<span style='color:#38b2ac;'>$output</span>";
        } elseif (is_object($var)) {
            $output = "Object(" . get_class($var) . ") {\n";
            foreach (get_object_vars($var) as $key => $value) {
                $output .= "    [" . htmlspecialchars($key) . "] => " . trim(highlight_var($value)) . "\n";
            }
            $output .= "}";
            return "<span style='color:#805ad5;'>$output</span>";
        } elseif (is_string($var)) {
            return "<span style='color:#ecc94b;'>\"" . htmlspecialchars($var) . "\"</span>";
        } elseif (is_int($var)) {
            return "<span style='color:#63b3ed;'>$var</span>";
        } elseif (is_float($var)) {
            return "<span style='color:#4299e1;'>$var</span>";
        } elseif (is_bool($var)) {
            return "<span style='color:#f56565;'>" . ($var ? 'true' : 'false') . "</span>";
        } elseif (is_null($var)) {
            return "<span style='color:#a0aec0;'>null</span>";
        } else {
            return "<span>" . htmlspecialchars((string) $var) . "</span>";
        }
    }
}

if (!function_exists('dump')) {
    function dump(mixed $value, int $maxDepth = 10): mixed
    {
        return (new Dumper($maxDepth))->dump($value);
    }
}

// if (!function_exists('dump')) {
//     /**
//      * Dump variables without dying
//      * 
//      * @param mixed ...$vars
//      * @return void
//      */
//     function dump(...$vars): void
//     {
//         echo '<pre style="background: #1a202c; color: #e2e8f0; padding: 20px; border-radius: 8px; margin: 20px; font-family: monospace; font-size: 14px; line-height: 1.4;">';
//         echo '<div style="color: #ff6b35; font-weight: bold; margin-bottom: 10px;">🔍 Debug Dump</div>';

//         foreach ($vars as $i => $var) {
//             if (count($vars) > 1) {
//                 echo '<div style="color: #f6ad55; font-weight: bold;">Variable #' . ($i + 1) . ':</div>';
//             }
//             var_dump($var);
//             if ($i < count($vars) - 1) {
//                 echo "\n" . str_repeat('-', 50) . "\n";
//             }
//         }

//         echo '</pre>';
//     }
// }

// if (!function_exists('logger')) {
//     /**
//      * Log a message (if DebugBar is available)
//      * 
//      * @param string $message
//      * @param string $level
//      * @return void
//      */
//     function logger(string $message, string $level = 'info'): void
//     {
//         try {
//             $container = app();
//             if ($container->has(DebugBar::class)) {  // ← Checks if class exists
//                 $container->get(DebugBar::class)->addMessage($message, $level);
//             }
//         } catch (\Exception $e) {  // ← Catches if class doesn't exist
//             // DebugBar not available, silently ignore
//         }
//     }
// }

// if (!function_exists('debug_message')) {
//     /**
//      * Add a debug message (if DebugBar is available)
//      * 
//      * @param string $message
//      * @param string $type
//      * @return void
//      */
//     function debug_message(string $message, string $type = 'info'): void
//     {
//         try {
//             $container = app();
//             if ($container->has(DebugBar::class)) {
//                 $container->get(DebugBar::class)->addMessage($message, $type);
//             }
//         } catch (\Exception $e) {
//             // DebugBar not available, silently ignore
//         }
//     }
// }

// if (!function_exists('debug_timer_start')) {
//     /**
//      * Start a debug timer (if DebugBar is available)
//      * 
//      * @param string $name
//      * @return void
//      */
//     function debug_timer_start(string $name): void
//     {
//         try {
//             $container = app();
//             if ($container->has(DebugBar::class)) {
//                 $container->get(DebugBar::class)->startTimer($name);
//             }
//         } catch (\Exception $e) {
//             // DebugBar not available, silently ignore
//         }
//     }
// }

// if (!function_exists('debug_timer_end')) {
//     /**
//      * End a debug timer (if DebugBar is available)
//      * 
//      * @param string $name
//      * @return void
//      */
//     function debug_timer_end(string $name): void
//     {
//         try {
//             $container = app();
//             if ($container->has(DebugBar::class)) {
//                 $container->get(DebugBar::class)->endTimer($name);
//             }
//         } catch (\Exception $e) {
//             // DebugBar not available, silently ignore
//         }
//     }
// }

// if (!function_exists('debug_var')) {
//     /**
//      * Add a variable to debug output (if DebugBar is available)
//      * 
//      * @param string $name
//      * @param mixed $value
//      * @return void
//      */
//     function debug_var(string $name, $value): void
//     {
//         try {
//             $container = app();
//             if ($container->has(DebugBar::class)) {
//                 $container->get(DebugBar::class)->addVar($name, $value);
//             }
//         } catch (\Exception $e) {
//             // DebugBar not available, silently ignore
//         }
//     }
// }

}

// ── file.php ────────────────────────────────────────────────────────

namespace {

/**
 * File Helper Functions
 * 
 * Provides utility functions for file operations.
 * 
 * @package Nitro\Support
 * @author Zeeshan Ali 
 * @version 1.0
 */

if (!function_exists('file_get')) {
    /**
     * Get file contents with error handling
     * 
     * @param string $path
     * @param mixed $default
     * @return mixed
     */
    function file_get(string $path, $default = null)
    {
        if (!file_exists($path)) {
            return $default;
        }

        $contents = file_get_contents($path);
        return $contents !== false ? $contents : $default;
    }
}

if (!function_exists('file_put')) {
    /**
     * Put contents to file with error handling
     * 
     * @param string $path
     * @param string $contents
     * @param bool $append
     * @return bool
     */
    function file_put(string $path, string $contents, bool $append = false): bool
    {
        $flags = $append ? FILE_APPEND | LOCK_EX : LOCK_EX;
        return file_put_contents($path, $contents, $flags) !== false;
    }
}

if (!function_exists('file_size')) {
    /**
     * Get human readable file size
     * 
     * @param string $path
     * @return string|null
     */
    function file_size(string $path): ?string
    {
        if (!file_exists($path)) {
            return null;
        }

        $size = filesize($path);
        return $size !== false ? format_bytes($size) : null;
    }
}

}

// ── http.php ────────────────────────────────────────────────────────

namespace {

/**
 * HTTP helper functions.
 *
 * NOTE: the former not_found / forbidden / unauthorized / server_error /
 * bad_request shorthand helpers were removed in favor of calling
 * `abort($code, $message)` directly (Laravel-style).
 *
 * The is_ajax / is_post / is_get / user_agent / client_ip globals were also
 * removed; use the Request instance instead:
 *
 *   request()->ajax()
 *   request()->isMethod('POST')
 *   request()->header('user-agent')
 *   request()->ip()
 *
 * This file is intentionally minimal; everything HTTP-related is exposed via
 * the Request object, the abort() helper, or the Response factories.
 */

}

// ── request.php ─────────────────────────────────────────────────────

namespace {

use Nitro\Http\Request;

if (!function_exists('request')) {
    function request(?string $key = null, $default = null)
    {
        $request = app(Request::class);

        if ($key === null) {
            return $request;
        }

        return $request->get($key, $default);
    }
}

if (!function_exists('nitro_current_request')) {
    /**
     * The bound HTTP Request, or null when none is bound (console, queued jobs,
     * early bootstrap). Input/URL helpers proxy this instead of reading PHP
     * superglobals directly, so they see exactly what the app's Request sees —
     * and stay correct in worker mode where 'request' is rebound per request.
     * Callers fall back to the raw superglobal only when this returns null.
     */
    function nitro_current_request(): ?Request
    {
        $container = app();

        if (!$container->has('request')) {
            return null;
        }

        $request = $container->resolve('request');

        return $request instanceof Request ? $request : null;
    }
}

if (!function_exists('input')) {
    /**
     * Alias for request() function
     *
     * @param string $key Input key
     * @param mixed $default Default value if key not found
     * @return mixed
     */
    function input(string $key, $default = null)
    {
        return request($key, $default);
    }
}

if (!function_exists('post')) {
    /**
     * Get POST input
     *
     * @param string $key Input key
     * @param mixed $default Default value if key not found
     * @return mixed
     */
    function post(string $key, $default = null)
    {
        $request = nitro_current_request();

        return $request ? $request->post($key, $default) : ($_POST[$key] ?? $default);
    }
}

if (!function_exists('get')) {
    /**
     * Get GET input
     *
     * @param string $key Input key
     * @param mixed $default Default value if key not found
     * @return mixed
     */
    function get(string $key, $default = null)
    {
        $request = nitro_current_request();

        return $request ? $request->query($key, $default) : ($_GET[$key] ?? $default);
    }
}

if (!function_exists('files')) {
    /**
     * Uploaded files as {@see \Nitro\Http\UploadedFile} instances.
     *
     * Dot notation reaches into array inputs: files('docs.0').
     *
     * @param string|null $key File input name, or null for all of them.
     * @return \Nitro\Http\UploadedFile|array<mixed>|null
     */
    function files(?string $key = null)
    {
        $request = nitro_current_request();

        if ($request !== null) {
            return $key === null ? $request->allFiles() : $request->file($key);
        }

        // No bound request (console, early boot) — normalize the superglobal.
        $all = \Nitro\Http\FileBag::normalize($_FILES);

        return $key === null ? $all : ($all[$key] ?? null);
    }
}

if (!function_exists('has_file')) {
    /**
     * Whether a file arrived under $key.
     *
     * @param string $key
     * @return bool
     */
    function has_file(string $key): bool
    {
        $request = nitro_current_request();

        if ($request !== null) {
            return $request->hasFile($key);
        }

        $file = files($key);

        return $file instanceof \Nitro\Http\UploadedFile && $file->getPathname() !== '';
    }
}

}

// ── response.php ────────────────────────────────────────────────────

namespace {

use Nitro\Exceptions\HttpException;
use Nitro\Http\RedirectResponse;
use Nitro\Http\Redirector;
use Nitro\Http\Response;
use Nitro\Http\ResponseFactory;

if (!function_exists('json')) {
    /**
     * Send a JSON response
     * 
     * @param array $data Data to encode as JSON
     * @param int $status HTTP status code
     * @param array $headers Additional headers
     * @return void
     */
    function json(array $data, int $status = 200, array $headers = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json');

        foreach ($headers as $name => $value) {
            header("$name: $value");
        }

        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

if (!function_exists('text')) {
    /**
     * Send a plain text response
     * 
     * @param string $text Text to send
     * @param int $status HTTP status code
     * @return void
     */
    function text(string $text, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $text;
    }
}

if (!function_exists('html')) {
    /**
     * Send an HTML response
     * 
     * @param string $html HTML to send
     * @param int $status HTTP status code
     * @return void
     */
    function html(string $html, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
    }
}

if (!function_exists('response')) {
    /**
     * Get the response factory, or build a response.
     *
     * Called with no arguments it hands back the factory, which is what makes
     * `response()->view(...)` and `response()->json(...)` work. Given content
     * it builds a response instead.
     *
     * @param array<string, string> $headers
     */
    function response(string $content = '', int $status = 200, array $headers = []): ResponseFactory|Response
    {
        $factory = app(ResponseFactory::class);

        if (func_num_args() === 0) {
            return $factory;
        }

        return $factory->make($content, $status, $headers);
    }
}

if (!function_exists('redirect')) {
    /**
     * Laravel-style redirect helper.
     *
     * - redirect('/path')  → a RedirectResponse (chain ->withInput()/->withErrors()/->with()).
     * - redirect()         → a Redirector for fluent ->route()/->intended()/->back()/->away().
     *
     * @param string|null $url URL to redirect to, or null for the fluent builder
     * @param int $status HTTP status code (301, 302, …)
     * @return RedirectResponse|Redirector
     */
    function redirect(?string $url = null, int $status = 302)
    {
        $redirector = new Redirector();
        return $url !== null ? $redirector->to($url, $status) : $redirector;
    }
}

if (!function_exists('back')) {
    /**
     * Build a redirect response to the previous page (Referer), or $fallback
     * when there's no referer. Chainable like redirect().
     *
     * @param string $fallback URL to use when no Referer header is present
     * @param int $status HTTP status code
     * @return RedirectResponse
     */
    function back(string $fallback = '/', int $status = 302): RedirectResponse
    {
        $referer = app('request')->header('referer') ?? $fallback;
        return Response::redirect($referer, $status);
    }
}

if (!function_exists('abort')) {
    /**
     * Abort the request with an HTTP status code by throwing an HttpException.
     *
     * Throwing (rather than the old echo + exit) routes the failure through the
     * Kernel's exception handler, so it gets proper status, HTMX/JSON content
     * negotiation and the response-ready hooks — instead of a raw <h1> that
     * bypassed the whole lifecycle. HttpException extends RuntimeException.
     *
     * @param int    $code    HTTP status code
     * @param string $message Error message (defaults to the status text)
     * @return never
     *
     * @throws \Nitro\Exceptions\HttpException
     */
    function abort(int $code, string $message = ''): never
    {
        if ($message === '') {
            $message = [
                400 => 'Bad Request',
                401 => 'Unauthorized',
                403 => 'Forbidden',
                404 => 'Not Found',
                405 => 'Method Not Allowed',
                419 => 'Page Expired',
                422 => 'Unprocessable Entity',
                429 => 'Too Many Requests',
                500 => 'Internal Server Error',
                502 => 'Bad Gateway',
                503 => 'Service Unavailable',
            ][$code] ?? 'Error';
        }

        throw new \Nitro\Exceptions\HttpException($code, $message);
    }
}

if (!function_exists('abort_if')) {
    /**
     * Refuse when the condition holds.
     *
     *     abort_if($order->user_id !== auth()->id(), 404);
     *
     * The same as an if with an abort inside it, and worth having because the
     * long form invites the mistake: a guard written as a statement gets an
     * early return bolted on later, or a second branch, and the refusal quietly
     * stops covering the case it was written for. One line does not.
     *
     * @param  array<string, string>  $headers
     *
     * @throws \Nitro\Exceptions\HttpException
     */
    function abort_if(bool $condition, int $code, string $message = '', array $headers = []): void
    {
        if (! $condition) {
            return;
        }

        if ($headers === []) {
            abort($code, $message);
        }

        throw (new \Nitro\Exceptions\HttpException($code, $message))->withHeaders($headers);
    }
}

if (!function_exists('abort_unless')) {
    /**
     * Refuse unless the condition holds.
     *
     *     abort_unless($certificate->isVisibleTo(auth()->user()), 404);
     *
     * The form most authorisation reads in: a thing that must be true, said
     * once, at the top.
     *
     * @param  array<string, string>  $headers
     *
     * @throws \Nitro\Exceptions\HttpException
     */
    function abort_unless(bool $condition, int $code, string $message = '', array $headers = []): void
    {
        abort_if(! $condition, $code, $message, $headers);
    }
}

}

// ── security.php ────────────────────────────────────────────────────

namespace {

if (!function_exists('escape')) {
    /**
     * Escape HTML special characters
     * 
     * @param string $value
     * @return string
     */
    function escape(string $value): string
    {
        // ENT_SUBSTITUTE so invalid UTF-8 yields the replacement char rather
        // than an empty string; double-encoding stays on (htmlspecialchars
        // default) — matches nitro_e() and Laravel's e().
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('e')) {
    /**
     * Alias for escape() function
     * 
     * @param string $value
     * @return string
     */
    function e(string $value): string
    {
        return escape($value);
    }
}

if (!function_exists('csrf_token')) {
    /**
     * The current session's CSRF token, or an empty string when there is no
     * session.
     *
     * A read, never a write. The token is minted by the session itself the
     * moment it starts, so by the time a route in the session group renders a
     * form the token is already there. Minting here instead meant that asking
     * for a token created a session: a stateless JSON route, or a view compiler
     * warming itself at construction, would take an id, set a cookie and leave
     * a file behind for a request that had no state to keep.
     *
     * An empty string is the honest answer for a route with no session. A form
     * on such a route could not be verified against one anyway.
     */
    function csrf_token(): string
    {
        try {
            $session = nitro_session();
        } catch (\Throwable) {
            return '';
        }

        if (! $session->isStarted()) {
            return '';
        }

        $token = $session->get('_csrf');

        return is_string($token) ? $token : '';
    }
}

if (!function_exists('csrf_field')) {
    /**
     * Generate CSRF hidden field
     * 
     * @return string
     */
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . escape(csrf_token()) . '">';
    }
}

if (!function_exists('verify_csrf')) {
    /**
     * Verify CSRF token
     * 
     * @param string|null $token
     * @return bool
     */
    function verify_csrf(?string $token = null): bool
    {
        $token = $token ?? (post('_token') ?: request('_token'));
        return $token && hash_equals(csrf_token(), $token);
    }
}

}

// ── auth.php ────────────────────────────────────────────────────────

namespace {

use Nitro\Auth\AuthManager;
use Nitro\Auth\Contracts\Guard;

if (!function_exists('auth')) {
    /**
     * The authentication manager, or one of its guards by name.
     *
     * Calls the manager does not answer go to the default guard, so
     * auth()->user() reads the same whether an application has one
     * guard or several.
     */
    function auth(?string $guard = null): AuthManager|Guard
    {
        $auth = app('auth');

        return $guard === null ? $auth : $auth->guard($guard);
    }
}

}

// ── session.php ─────────────────────────────────────────────────────

namespace {

use Nitro\Session\Contracts\Session;

if (!function_exists('session')) {
    /**
     * Get or set session data through the bound session store.
     *
     * - session()                  → the store instance
     * - session('key')             → value (or null)
     * - session('key', $default)   → value (or $default)
     * - session(['k' => 'v', ...]) → set pairs, returns true
     *
     * @param string|array|null $key
     * @param mixed $default
     * @return mixed
     */
    function session($key = null, $default = null)
    {
        /** @var Session $store */
        $store = nitro_session();

        if ($key === null) {
            // Laravel-style: bare session() yields the store for ->method() calls.
            return $store;
        }

        if (is_array($key)) {
            $store->put($key);
            return true;
        }

        // Every string form is a read, including the two-argument one. Writing
        // a single key here would make session('basket', []) — an ordinary read
        // with a default — empty the basket rather than return one, silently
        // and on every request. Write with session(['key' => $value]) or
        // session()->put(), as the docblock above says.
        return $store->get($key, $default);
    }
}

if (!function_exists('nitro_session')) {
    /**
     * Resolve the request's session store.
     *
     * Reading the store does not start it. Only {@see \Nitro\Session\Middleware\StartSession}
     * does that, so a session exists when the matched route asked for one and
     * never because some helper wanted to look something up. Starting on read
     * meant a stateless JSON route could mint a session — and with it an id, a
     * cookie and a file on disk — merely by calling csrf_token().
     *
     * On a route with no session the store answers empty and discards writes,
     * which is the same thing a session that was never started would do.
     *
     * @return Session
     */
    function nitro_session(): Session
    {
        return app('session');
    }
}

if (!function_exists('session_forget')) {
    /**
     * Remove session data.
     */
    function session_forget(string $key): void
    {
        nitro_session()->forget($key);
    }
}

if (!function_exists('session_flush')) {
    /**
     * Clear all session data.
     */
    function session_flush(): void
    {
        nitro_session()->flush();
    }
}

if (!function_exists('flash')) {
    /**
     * Flash data to the session (available on the next request).
     */
    function flash(string $key, $value): void
    {
        nitro_session()->flash($key, $value);
    }
}

if (!function_exists('old')) {
    /**
     * Get old input data (from the previous request).
     */
    function old(string $key, $default = ''): string
    {
        $input = nitro_session()->get('_old_input', []);
        $value = is_array($input) ? ($input[$key] ?? $default) : $default;
        return (string) $value;
    }
}

if (!function_exists('errors')) {
    /**
     * Get validation errors stored in the session (from the previous request).
     *
     * @param string|null $key Optional field name for a single error.
     * @return array|string
     */
    function errors(?string $key = null)
    {
        $err = nitro_session()->get('errors', []);
        if (!is_array($err)) {
            $err = [];
        }
        if ($key === null) {
            return $err;
        }
        return $err[$key] ?? '';
    }
}

}

// ── string.php ──────────────────────────────────────────────────────

namespace {

if (!function_exists('str_contains')) {
    /**
     * Check if string contains substring (PHP 8 polyfill)
     * 
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle !== '' && mb_strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_starts_with')) {
    /**
     * Check if string starts with substring (PHP 8 polyfill)
     * 
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    function str_starts_with(string $haystack, string $needle): bool
    {
        return (string) $needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    /**
     * Check if string ends with substring (PHP 8 polyfill)
     * 
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    function str_ends_with(string $haystack, string $needle): bool
    {
        return $needle !== '' && substr($haystack, -strlen($needle)) === (string) $needle;
    }
}

if (!function_exists('str_slug')) {
    /**
     * Generate a URL-friendly slug
     * 
     * @param string $title
     * @param string $separator
     * @return string
     */
    function str_slug(string $title, string $separator = '-'): string
    {
        // Convert to lowercase
        $slug = mb_strtolower($title, 'UTF-8');

        // Replace non-alphanumeric characters with separator
        $slug = preg_replace('/[^a-z0-9]+/i', $separator, $slug);

        // Remove leading/trailing separators
        $slug = trim($slug, $separator);

        return $slug;
    }
}

if (!function_exists('str_limit')) {
    /**
     * Limit string length
     * 
     * @param string $value
     * @param int $limit
     * @param string $end
     * @return string
     */
    function str_limit(string $value, int $limit = 100, string $end = '...'): string
    {
        if (mb_strlen($value, 'UTF-8') <= $limit) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $limit, 'UTF-8')) . $end;
    }
}


if (!function_exists('class_basename')) {
    /**
     * Get the class "basename" of the given object / class.
     *
     * @param string|object $class
     * @return string
     */
    function class_basename($class)
    {
        $class = is_object($class) ? get_class($class) : $class;
        
        return basename(str_replace('\\', '/', $class));
    }
}
if (!function_exists('trait_uses_recursive')) {
    /**
     * Every trait used by a trait, including traits used by those traits.
     *
     * @param  string|object  $trait
     * @return array<string, string>
     */
    function trait_uses_recursive($trait)
    {
        $traits = class_uses($trait) ?: [];

        foreach ($traits as $used) {
            $traits += trait_uses_recursive($used);
        }

        return $traits;
    }
}

if (!function_exists('class_uses_recursive')) {
    /**
     * Every trait used by a class, its parents, and its traits' traits.
     *
     * Walks up the inheritance chain because a trait used by a base class is
     * used by the subclass too — a model boot hook declared on a trait the
     * parent brought in still has to run for the child.
     *
     * @param  string|object  $class
     * @return array<string, string>
     */
    function class_uses_recursive($class)
    {
        if (is_object($class)) {
            $class = get_class($class);
        }

        $results = [];

        foreach (array_reverse(class_parents($class) ?: []) + [$class => $class] as $one) {
            $results += trait_uses_recursive($one);
        }

        return array_unique($results);
    }
}

if (!function_exists('str')) {
    /**
     * Begin a fluent string chain.
     *
     * Called with no argument it returns the Str class name, so
     * str()::slug(...) and str($v)->slug() both work.
     *
     * @return \Nitro\Support\Stringable|class-string<\Nitro\Support\Str>
     */
    function str(?string $string = null)
    {
        if ($string === null) {
            return \Nitro\Support\Str::class;
        }

        return new \Nitro\Support\Stringable($string);
    }
}

}

// ── url.php ─────────────────────────────────────────────────────────

namespace {

if (!function_exists('asset')) {
    /**
     * Generate an asset URL
     *
     * @param string $path Asset path
     * @return string
     */
    function asset(string $path): string
    {
        // Simple version for now - just prepend slash
        // Later you can make this configurable via config('app.asset_url')
        return '/' . ltrim($path, '/');
    }
}

if (!function_exists('url')) {
    /**
     * Generate a full URL for the given path
     *
     * @param string $path URL path
     * @return string
     */
    function url(string $path = ''): string
    {
        $request = nitro_current_request();

        if ($request) {
            $scheme = $request->secure() ? 'https' : 'http';
            $host   = (string) $request->server('HTTP_HOST', 'localhost');
        } else {
            $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        }

        if (empty($path)) {
            return $scheme . '://' . $host;
        }

        return $scheme . '://' . $host . '/' . ltrim($path, '/');
    }
}

if (!function_exists('method_field')) {
    function method_field(string $method): string
    {
        return '<input type="hidden" name="_method" value="' . strtoupper($method) . '">';
    }
}

// csrf_token()/csrf_field() are defined canonically in security.php (session
// key '_csrf', CSPRNG-minted). They are intentionally NOT redefined here — a
// second definition reading a different session key ('_token') is a load-order
// landmine that would silently break CSRF verification.

if (!function_exists('route')) {
    /**
     * The URL of a named route.
     *
     * Throws on an unknown name rather than returning a placeholder: the route
     * table is fixed at boot, so a miss is a programming error, not a runtime
     * condition.
     *
     * @param  string  $name  Route name
     * @param  mixed  $parameters  One parameter, or an array of them
     *
     * @throws \InvalidArgumentException when no route has that name.
     */
    function route(string $name, mixed $parameters = []): string
    {
        // Accept a bare parameter as well as an array.
        if (! is_array($parameters)) {
            $parameters = [$parameters];
        }

        // Held between calls: a page of links would otherwise resolve the same
        // singleton once per link. Keyed on the container so a reset (tests,
        // worker teardown) does not hand back a stale router.
        static $router = null;
        static $from = null;

        $container = app();

        if ($router === null || $from !== $container) {
            $from = $container;
            $router = $container->resolve('router');
        }

        return $router->route($name, $parameters);
    }
}

if (!function_exists('current_url')) {
    /**
     * Get the current full URL (scheme, host, path and query string).
     *
     * @return string
     */
    function current_url(): string
    {
        $request = nitro_current_request();

        if ($request) {
            return $request->fullUrl();
        }

        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri    = $_SERVER['REQUEST_URI'] ?? '/';

        return $scheme . '://' . $host . $uri;
    }
}

if (!function_exists('current_path')) {
    /**
     * Get the current path (without domain)
     *
     * @return string
     */
    function current_path(): string
    {
        $request = nitro_current_request();

        return $request ? $request->path() : ($_SERVER['REQUEST_URI'] ?? '/');
    }
}

if (!function_exists('secure_url')) {
    /**
     * Generate a secure HTTPS URL
     *
     * @param string $path
     * @return string
     */
    function secure_url(string $path = ''): string
    {
        $request = nitro_current_request();
        $host    = $request
            ? (string) $request->server('HTTP_HOST', 'localhost')
            : ($_SERVER['HTTP_HOST'] ?? 'localhost');

        if (empty($path)) {
            return 'https://' . $host;
        }

        return 'https://' . $host . '/' . ltrim($path, '/');
    }
}

}

// ── utility.php ─────────────────────────────────────────────────────

namespace {

if (!function_exists('now')) {
    /**
     * The current date and time.
     *
     * Returns a Carbon instance, so ->addDays(), ->diffForHumans(),
     * ->format() and comparison against other dates all work. For a Unix
     * timestamp use now()->getTimestamp() or time().
     */
    function now(?string $timezone = null): \Nitro\Support\Carbon
    {
        return \Nitro\Support\Carbon::now($timezone);
    }
}

if (!function_exists('today')) {
    /** The current date at midnight. */
    function today(?string $timezone = null): \Nitro\Support\Carbon
    {
        return \Nitro\Support\Carbon::today($timezone);
    }
}

if (!function_exists('date_make')) {
    /** Build a Carbon instance from anything date-like, or null. */
    function date_make(mixed $value): ?\Nitro\Support\Carbon
    {
        return \Nitro\Support\Carbon::make($value);
    }
}

if (!function_exists('uuid')) {
    /**
     * Generate a UUID v4
     * 
     * @return string
     */
    function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Variant bits

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('random_string')) {
    /**
     * Generate a random string
     * 
     * @param int $length
     * @param string $characters
     * @return string
     */
    function random_string(int $length = 10, string $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'): string
    {
        $charactersLength = strlen($characters);
        $randomString = '';

        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }

        return $randomString;
    }
}

if (!function_exists('format_bytes')) {
    /**
     * Format bytes to human readable format
     * 
     * @param int $size
     * @param int $precision
     * @return string
     */
    function format_bytes(int $size, int $precision = 2): string
    {
        if ($size === 0)
            return '0 B';

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $factor = floor(log($size, 1024));
        $factor = min($factor, count($units) - 1);

        return sprintf("%.{$precision}f %s", $size / pow(1024, $factor), $units[$factor]);
    }
}

if (!function_exists('money_format')) {
    /**
     * Format number as currency
     * 
     * @param float $amount
     * @param string $currency
     * @param int $decimals
     * @return string
     */
    function money_format(float $amount, string $currency = '$', int $decimals = 2): string
    {
        return $currency . number_format($amount, $decimals);
    }
}

if (!function_exists('percentage')) {
    /**
     * Calculate percentage
     * 
     * @param float $value
     * @param float $total
     * @param int $decimals
     * @return float
     */
    function percentage(float $value, float $total, int $decimals = 2): float
    {
        if ($total == 0)
            return 0;
        return round(($value / $total) * 100, $decimals);
    }
}

if (!function_exists('human_time')) {
    /**
     * Convert seconds to human readable time
     * 
     * @param int $seconds
     * @return string
     */
    function human_time(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        } elseif ($seconds < 3600) {
            return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
        } elseif ($seconds < 86400) {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            return $hours . 'h ' . $minutes . 'm';
        } else {
            $days = floor($seconds / 86400);
            $hours = floor(($seconds % 86400) / 3600);
            return $days . 'd ' . $hours . 'h';
        }
    }
}

if (!function_exists('time_ago')) {
    /**
     * Get human readable time difference
     * 
     * @param int $timestamp
     * @return string
     */
    function time_ago(int $timestamp): string
    {
        $diff = time() - $timestamp;

        if ($diff < 60) {
            return 'just now';
        } elseif ($diff < 3600) {
            $minutes = floor($diff / 60);
            return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 2592000) {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } else {
            return date('M j, Y', $timestamp);
        }
    }
}

if (!function_exists('blank')) {
    /**
     * Check if value is blank
     * 
     * @param mixed $value
     * @return bool
     */
    function blank($value): bool
    {
        if (is_null($value)) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return count($value) === 0;
        }

        return empty($value);
    }
}

if (!function_exists('filled')) {
    /**
     * Check if value is filled (opposite of blank)
     * 
     * @param mixed $value
     * @return bool
     */
    function filled($value): bool
    {
        return !blank($value);
    }
}

if (!function_exists('retry')) {
    /**
     * Retry a callback a given number of times
     * 
     * @param int $times
     * @param callable $callback
     * @param int $sleep
     * @return mixed
     * @throws Exception
     */
    function retry(int $times, callable $callback, int $sleep = 0)
    {
        $attempts = 0;

        beginning:
        $attempts++;

        try {
            return $callback($attempts);
        } catch (Exception $exception) {
            if ($attempts >= $times) {
                throw $exception;
            }

            if ($sleep > 0) {
                usleep($sleep * 1000);
            }

            goto beginning;
        }
    }
}

if (!function_exists('rescue')) {
    /**
     * Catch exceptions and return default value
     * 
     * @param callable $callback
     * @param mixed $rescue
     * @return mixed
     */
    function rescue(callable $callback, $rescue = null, bool $report = true)
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            // Reported by default, because the alternative is a helper whose
            // whole purpose is to swallow exceptions doing precisely that. A
            // rescue() around a best-effort write — logging an email, warming a
            // cache — is right to carry on, and wrong to leave no trace of why
            // it had to. Pass false where the failure genuinely is an expected
            // path rather than a fault.
            if ($report) {
                report($exception);
            }

            return is_callable($rescue) ? $rescue($exception) : $rescue;
        }
    }
}

if (!function_exists('report')) {
    /**
     * Record an exception and carry on.
     *
     * For the catch block that means "this is worth knowing about but must not
     * stop what we are doing" — writing the mail log, say, which must never be
     * the reason a certificate email fails to go out. Without it, such a block
     * either reaches for the handler by hand or, far more often, swallows the
     * exception and leaves nothing behind.
     *
     * Never throws: something that fails while reporting a failure must not
     * replace the failure.
     */
    function report(Throwable|string $exception): void
    {
        if (is_string($exception)) {
            $exception = new Exception($exception);
        }

        try {
            app(\Nitro\Exceptions\ExceptionHandler::class)->report($exception);
        } catch (Throwable) {
            // No application, or a handler that is itself broken. The PHP error
            // log keeps the trail rather than losing it.
            error_log((string) $exception);
        }
    }
}

if (!function_exists('report_if')) {
    /** Record an exception when the condition holds. */
    function report_if(bool $condition, Throwable|string $exception): void
    {
        if ($condition) {
            report($exception);
        }
    }
}

if (!function_exists('report_unless')) {
    /** Record an exception unless the condition holds. */
    function report_unless(bool $condition, Throwable|string $exception): void
    {
        report_if(! $condition, $exception);
    }
}

if (!function_exists('throw_if')) {
    /**
     * Throw exception if condition is true
     * 
     * @param bool $condition
     * @param string|Throwable $exception
     * @param string $message
     * @return void
     * @throws Exception
     */
    function throw_if(bool $condition, $exception, string $message = '')
    {
        if ($condition) {
            if (is_string($exception)) {
                throw new Exception($exception);
            }

            if ($exception instanceof Throwable) {
                throw $exception;
            }

            throw new Exception($message);
        }
    }
}

if (!function_exists('throw_unless')) {
    /**
     * Throw exception if condition is false
     * 
     * @param bool $condition
     * @param string|Throwable $exception
     * @param string $message
     * @return void
     * @throws Exception
     */
    function throw_unless(bool $condition, $exception, string $message = ''): void
    {
        throw_if(!$condition, $exception, $message);
    }
}

}

// ── validation.php ──────────────────────────────────────────────────

namespace {

use Nitro\Container\Container;
use Nitro\Validation\Factory as ValidationFactory;
use Nitro\Validation\Validator;

if (! function_exists('validator')) {
    /**
     * The validator factory, or a validator for this data.
     *
     *   validator($data, ['email' => 'required|email'])->validated();
     *   validator()->extend('uppercase', fn ($attribute, $value) => ...);
     *
     * Goes through the application's factory when there is one, so rules added
     * with Validator::extend() apply; a fresh factory otherwise, as in a test
     * with no application.
     *
     * @param array<string, mixed>|null $data
     * @param array<string, mixed>      $rules
     * @param array<string, string>     $messages
     * @param array<string, string>     $attributes
     */
    function validator(?array $data = null, array $rules = [], array $messages = [], array $attributes = []): ValidationFactory|Validator
    {
        $factory = Container::hasInstance() && Container::getInstance()->has(ValidationFactory::class)
            ? Container::getInstance()->resolve(ValidationFactory::class)
            : new ValidationFactory();

        if (func_num_args() === 0) {
            return $factory;
        }

        return $factory->make($data ?? [], $rules, $messages, $attributes);
    }
}

if (!function_exists('validate_email')) {
    /**
     * Validate email address
     * 
     * @param string $email
     * @return bool
     */
    function validate_email(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('validate_url')) {
    /**
     * Validate URL
     * 
     * @param string $url
     * @return bool
     */
    function validate_url(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}

if (!function_exists('validate_ip')) {
    /**
     * Validate IP address
     * 
     * @param string $ip
     * @return bool
     */
    function validate_ip(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
}

if (!function_exists('is_json')) {
    /**
     * Check if string is valid JSON
     * 
     * @param string $string
     * @return bool
     */
    function is_json(string $string): bool
    {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
}

}

// ── view.php ────────────────────────────────────────────────────────

namespace {

use Nitro\Http\Response;
use Nitro\View\View;
use Nitro\View\Support\Htmlable;

if (!function_exists('nitro_e')) {
    /**
     * Inline HTML-escape used by compiled {{ }} echoes. A global function
     * is dispatched faster than $this->e() — PHP's opcache + Zend engine
     * optimize free-function calls more aggressively than method calls,
     * and the call doesn't require a vtable lookup.
     *
     * Htmlable instances render themselves untouched so component slots
     * and pre-rendered HtmlString fragments survive the echo without
     * double-escaping. Everything else goes through htmlspecialchars with
     * Laravel's exact flags: ENT_QUOTES | ENT_SUBSTITUTE (so invalid UTF-8
     * becomes the replacement char instead of an empty string) and
     * double-encoding on (matching e()/escape() and Laravel's Blade).
     */
    function nitro_e(mixed $value): string
    {
        if ($value instanceof Htmlable) {
            return $value->toHtml();
        }
        if ($value === null) {
            return '';
        }
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('view')) {
    /**
     * Render a view with data.
     * 
     * When called from a controller/route → returns Response (for HTTP output).
     * When cast to string (e.g. inside @widget) → returns HTML via Response::__toString().
     * 
     * @param string $view View name (e.g., 'welcome', 'dashboard.index')
     * @param array<string, mixed> $data Data to pass to the view
     * @return Response
     */
    function view(string $view, array $data = []): Response
    {
        $content = app('view')->render($view, $data);
        $response = new Response($content);
        $response->header('Content-Type', 'text/html; charset=utf-8');
        return $response;
    }
}

if (!function_exists('component')) {
    /**
     * Render a component with props
     * 
     * @param string $name Component name (auto-prefixed with 'components.')
     * @param array $props Component props
     * @return string Rendered HTML
     */
    function component(string $name, array $props = []): string
    {
        if (!str_starts_with($name, 'components.')) {
            $name = 'components.' . $name;
        }

        return app('view.factory')->renderPartial($name, $props);
    }
}

}

// ── query.php ───────────────────────────────────────────────────────

namespace {

use Nitro\Database\Query\QueryRegistry;

if (!function_exists('query')) {
    /**
     * The named-query registry, or a named query resolved to a live builder.
     *
     *   query()                             // the registry (->register(), ->has(), ->names())
     *   query('students.honor_roll')        // resolve → a builder you can chain
     *   query('students.in', ['Lahore'])    // resolve with parameters
     *
     * @return QueryRegistry|\Nitro\Database\Query\QueryBuilder|mixed
     */
    function query(?string $name = null, array $params = [])
    {
        $registry = app(QueryRegistry::class);

        if ($name === null) {
            return $registry;
        }

        return $registry->resolve($name, $params);
    }
}

}

// ── cache.php ───────────────────────────────────────────────────────

namespace {

if (! function_exists('cache')) {
    function cache(?string $key = null, mixed $default = null)
    {
        $cache = app('cache')->store();

        if ($key === null) {
            return $cache;
        }

        return $cache->get($key, $default);
    }
}

}

// ── cookie.php ──────────────────────────────────────────────────────

namespace {

use Nitro\Cookie\CookieJar;
use Nitro\Http\Cookie;

if (! function_exists('cookie')) {
    /**
     * Access the cookie jar, or build a cookie.
     *
     *   cookie()                      → the CookieJar (queue via ->queue(...))
     *   cookie('name', 'value', 60)   → a Cookie (attach via response->withCookie)
     */
    function cookie(
        ?string $name = null,
        string $value = '',
        int $minutes = 0,
        ?string $path = null,
        ?string $domain = null,
        ?bool $secure = null,
        bool $httpOnly = true,
    ): Cookie|CookieJar {
        /** @var CookieJar $jar */
        $jar = app('cookie');

        if ($name === null) {
            return $jar;
        }

        return $jar->make($name, $value, $minutes, $path, $domain, $secure, $httpOnly);
    }
}

}

// ── translation.php ─────────────────────────────────────────────────

namespace {

use Nitro\Translation\Translator;

if (! function_exists('trans')) {
    /**
     * Translate a key, or get the translator itself when given none.
     *
     * @param array<string, mixed> $replace
     */
    function trans(?string $key = null, array $replace = [], ?string $locale = null, bool $fallback = true): mixed
    {
        $translator = app('translator');

        if ($key === null) {
            return $translator;
        }

        return $translator->get($key, $replace, $locale, $fallback);
    }
}

if (! function_exists('__')) {
    /**
     * Translate a key.
     *
     * @param array<string, mixed> $replace
     */
    function __(?string $key = null, array $replace = [], ?string $locale = null, bool $fallback = true): mixed
    {
        return trans($key, $replace, $locale, $fallback);
    }
}

if (! function_exists('trans_choice')) {
    /**
     * Translate a key, picking the plural form that matches the count.
     *
     * @param \Countable|array<mixed>|int $number
     * @param array<string, mixed>        $replace
     */
    function trans_choice(string $key, mixed $number, array $replace = [], ?string $locale = null): string
    {
        return app('translator')->choice($key, $number, $replace, $locale);
    }
}

if (! function_exists('lang_path')) {
    /**
     * Get the path to the application's language files.
     */
    function lang_path(string $path = ''): string
    {
        return app('paths')->lang($path);
    }
}

}

// ── inertia.php ─────────────────────────────────────────────────────

namespace {

use Nitro\Inertia\Response;
use Nitro\Inertia\ResponseFactory;

if (!function_exists('inertia')) {
    /**
     * Render an Inertia page, or get the factory.
     *
     * Called with a component name it builds the page; called with nothing it
     * hands back the factory, which is what makes `inertia()->share(...)` and
     * `inertia()->location(...)` read the way they do.
     *
     * @param  array<array-key, mixed> $props
     * @return ($component is null ? ResponseFactory : Response)
     */
    function inertia(?string $component = null, array $props = []): ResponseFactory|Response
    {
        $factory = app('inertia');

        return $component === null ? $factory : $factory->render($component, $props);
    }
}

if (!function_exists('inertia_location')) {
    /**
     * Send the client to a URL outside the application.
     *
     * Needed because a plain redirect cannot leave an Inertia app: the client
     * follows it with fetch and gets a document it cannot mount.
     */
    function inertia_location(string $url): Nitro\Http\Response
    {
        return app('inertia')->location($url);
    }
}

}

// ── event.php ───────────────────────────────────────────────────────

namespace {

use Nitro\Broadcasting\PendingBroadcast;
use Nitro\Events\Contracts\Dispatcher;
use Nitro\Events\QueuedClosure;

if (! function_exists('event')) {
    /**
     * Dispatch an event.
     *
     *   event(new OrderPlaced($order));
     *   event('report.ready', [$report]);
     *
     * @param  array<int, mixed>|mixed $payload
     * @return array<int, mixed>|mixed What the listeners returned.
     */
    function event(string|object $event, mixed $payload = [], bool $halt = false): mixed
    {
        return app(Dispatcher::class)->dispatch($event, $payload, $halt);
    }
}

if (! function_exists('broadcast')) {
    /**
     * Dispatch an event, with the broadcast-specific options to hand.
     *
     *   broadcast(new MessageSent($message))->toOthers();
     *   broadcast(new StockMoved($item))->via('redis');
     *
     * The event goes through the same dispatcher as event(), so its listeners
     * still run — this only gives the call site somewhere to say "not back to
     * the sender" and "on that connection". Without the chained call it
     * behaves exactly like event().
     */
    function broadcast(object $event): PendingBroadcast
    {
        return new PendingBroadcast(app(Dispatcher::class), $event);
    }
}

if (! function_exists('queueable')) {
    /**
     * Wrap a closure listener so it runs on the queue.
     *
     *   Event::listen(queueable(function (OrderPaid $event) {
     *       Receipt::for($event->orderId)->send();
     *   })->onQueue('mail'));
     */
    function queueable(Closure $closure): QueuedClosure
    {
        return new QueuedClosure($closure);
    }
}

}

// ── trace.php ───────────────────────────────────────────────────────

namespace {

use Nitro\Debug\Backtrace;
use Nitro\Debug\TraceRenderer;
use Nitro\Http\Response;

if (! function_exists('trace_renderer')) {
    /**
     * A renderer over the caller's stack, with the helper frames dropped.
     *
     * What is left is the path through the application; the line the
     * helper was put on is kept separately, as the origin.
     *
     * @param int $skip Helpers between this and the caller, each of
     *        which would otherwise appear as a frame of its own.
     */
    function trace_renderer(?string $label, int $skip = 0): TraceRenderer
    {
        $trace = Backtrace::capture($skip);

        return new TraceRenderer($trace->withoutTop(), $label, $trace->origin());
    }
}

if (! function_exists('trace')) {
    /**
     * The call stack as a response, to return from a controller.
     *
     *     public function index()
     *     {
     *         return trace();            // instead of return view(...)
     *     }
     *
     *     return trace('before the query');
     *
     * Shows every file and method that led here, in the order they were
     * called — entry point first, this line last. A request that asked
     * for JSON gets the frames as data instead.
     *
     * @param ?string $label       A note shown above the frames.
     * @param bool    $newestFirst Number from this call outwards instead.
     */
    function trace(?string $label = null, bool $newestFirst = false): Response
    {
        $renderer = trace_renderer($label, 1);

        if (trace_wants_json()) {
            return Response::json($renderer->toArray($newestFirst));
        }

        return (new Response($renderer->toHtml($newestFirst)))
            ->header('Content-Type', 'text/html; charset=utf-8');
    }
}

if (! function_exists('trace_text')) {
    /**
     * The same, as plain text.
     *
     * For a console command, a log line, or a test that asserts on the
     * path rather than reading it.
     */
    function trace_text(?string $label = null, bool $newestFirst = false): string
    {
        return trace_renderer($label, 1)->toText($newestFirst);
    }
}

if (! function_exists('dt')) {
    /**
     * Show the stack and stop, the way dd() shows a value and stops.
     *
     * For a place that cannot return a response — inside a view, a
     * model event, a queued job.
     */
    function dt(?string $label = null, bool $newestFirst = false): never
    {
        echo trace_renderer($label, 1)->toHtml($newestFirst);

        exit;
    }
}

if (! function_exists('trace_wants_json')) {
    /**
     * Whether the current request would rather have data than a page.
     *
     * Guarded, because this is a debugging helper and is as likely to
     * be called from a console command, where there is no request.
     */
    function trace_wants_json(): bool
    {
        try {
            $request = app('request');
        } catch (\Throwable) {
            return false;
        }

        return method_exists($request, 'expectsJson') && $request->expectsJson();
    }
}

}
