<?php

namespace Nitro\Notifications;

/**
 * The call-to-action button in a notification.
 */
class Action
{
    /**
     * @param string $text The button's label.
     * @param string $url  Where it points.
     */
    public function __construct(
        public string $text,
        public string $url,
    ) {}
}
