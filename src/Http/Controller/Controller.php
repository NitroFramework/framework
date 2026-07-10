<?php

namespace Nitro\Http\Controller;

use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Http\Controller\Concerns\BuildsResponses;
use Nitro\Http\Controller\Concerns\HandlesRequests;
use Nitro\Http\Controller\Concerns\InteractsWithDatabase;
use Nitro\Http\Controller\Concerns\PerformsValidation;
use Nitro\Http\Controller\Concerns\RendersViews;

/**
 * Base Controller Class for NitroPHP Framework
 * 
 * Provides common functionality and service access for all application controllers
 * via composition of focused traits.
 * 
 * DESIGN PRINCIPLES:
 * - Each trait handles a single concern (requests, responses, validation, database)
 * - Controller becomes an assembly layer with minimal logic
 * - Lazy service loading still applies within traits
 * - Clear and consistent API across all controllers
 */
abstract class Controller
{

    protected ContainerInterface $container;

    /**
     * Initialize Controller and container
     */
    public function __construct()
    {

        $this->container = app();
    }

    // ============================================
    // TRAITS
    // ============================================

    use HandlesRequests;
    use RendersViews;
    use BuildsResponses;
    use PerformsValidation;
    use InteractsWithDatabase;

    // ============================================
    // MAGIC SERVICE ACCESS (optional)
    // ============================================

    /**
     * Handle dynamic method calls for services not explicitly defined.
     * Allows legacy dynamic service access.
     */
    public function __call(string $name, array $arguments)
    {
        return app($name);
    }
}
