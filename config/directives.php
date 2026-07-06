<?php

use Nitro\View\Blade;

return function ($app) {

    Blade::directive('error', function ($expression) {
        return "<?php if(isset(\$errors[{$expression}][0])): ?>
            <span class=\"error-message\"><?php echo htmlspecialchars(\$errors[{$expression}][0], ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>";
    });

    Blade::directive('elapsed_time', function ($expression) {
        return "<?php echo \\Nitro\\PerformanceBar\\PerformanceMetrics::elapsedTime(); ?>";
    });

    Blade::directive('memory_usage', function ($expression) {
        return "<?php echo \\Nitro\\PerformanceBar\\PerformanceMetrics::memoryUsage(); ?>";
    });

    Blade::directive('current_memory', function ($expression) {
        return "<?php echo number_format(\\Nitro\\PerformanceBar\\PerformanceMetrics::getCurrentMemoryUsage(), 3); ?>";
    });

    Blade::directive('performance_stats', function ($expression) {
        return "<?php 
            \$stats = \\Nitro\\PerformanceBar\\PerformanceMetrics::getStats();
            echo 'Time: ' . number_format(\$stats['elapsed_time'] * 1000, 2) . 'ms | ';
            echo 'Memory: ' . number_format(\$stats['memory_usage'], 3) . 'MB';
            ?>";
    });

};