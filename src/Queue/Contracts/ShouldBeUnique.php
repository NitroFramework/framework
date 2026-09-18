<?php

namespace Nitro\Queue\Contracts;

/**
 * Marks a job that may only be queued once at a time.
 *
 *     class RebuildIndex extends Job implements ShouldBeUnique
 *     {
 *         public int $uniqueFor = 3600;
 *
 *         public function uniqueId(): string
 *         {
 *             return $this->siteId;
 *         }
 *     }
 *
 * A second dispatch while the first is still queued or running is dropped.
 * uniqueId() defaults to the class name, and $uniqueFor caps how long the
 * claim survives if a worker dies holding it.
 */
interface ShouldBeUnique
{
}
