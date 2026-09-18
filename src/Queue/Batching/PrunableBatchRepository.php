<?php

namespace Nitro\Queue\Batching;

/**
 * A batch store whose finished records can be swept.
 */
interface PrunableBatchRepository extends BatchRepository
{
    /**
     * Remove batches that finished before the given time.
     *
     * @return int Batches removed.
     */
    public function prune(\DateTimeInterface $before): int;
}
