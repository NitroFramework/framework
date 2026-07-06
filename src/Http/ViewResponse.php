<?php

namespace Nitro\Http;

/**
 * The DTO a view-route returns instead of rendered HTML: it carries the template
 * name and data, and the HTTP kernel renders it into a Response. Keeps route
 * handlers free of the view engine.
 */
class ViewResponse 
{
    public function __construct(
        public string $template,
        public array $data = []
    ) {}
}