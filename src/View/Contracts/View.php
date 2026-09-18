<?php

namespace Nitro\View\Contracts;

use Nitro\View\Support\Renderable;

/**
 * A chosen view and its data, rendered on demand.
 */
interface View extends Renderable
{
    /**
     * Get the name of the view.
     */
    public function name(): string;

    /**
     * Add a piece of data to the view.
     */
    public function with(string $key, mixed $value): static;

    /**
     * Merge an array of data into the view.
     *
     * @param array<string, mixed> $data
     */
    public function withData(array $data): static;

    /**
     * Get the data the view will render with.
     *
     * @return array<string, mixed>
     */
    public function getData(): array;
}
