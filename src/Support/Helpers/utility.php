<?php

if (!function_exists('now')) {
    /**
     * Get current timestamp
     * 
     * @return int
     */
    function now(): int
    {
        return time();
    }
}

if (!function_exists('today')) {
    /**
     * Get today's date
     * 
     * @param string $format
     * @return string
     */
    function today(string $format = 'Y-m-d'): string
    {
        return date($format);
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
        } catch (Exception $e) {
            if ($attempts >= $times) {
                throw $e;
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
    function rescue(callable $callback, $rescue = null)
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            return is_callable($rescue) ? $rescue($e) : $rescue;
        }
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