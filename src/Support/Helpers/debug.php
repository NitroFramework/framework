<?php

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
