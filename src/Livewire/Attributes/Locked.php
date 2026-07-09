<?php

namespace Nitro\Livewire\Attributes;

use Attribute;

/**
 * Marks a public component property as locked: the server will REJECT any
 * client-driven change to it (a wire:model update or a forged `updates` entry).
 *
 * Use it on values the browser must never mutate — record IDs, ownership keys,
 * prices — so a tampered request can't reassign them:
 *
 *   #[Locked]
 *   public int $studentId;
 *
 * Locked only blocks incoming client updates; the server may still set the
 * property in mount()/actions, and it round-trips through the (checksum-signed)
 * snapshot as normal.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Locked
{
}
