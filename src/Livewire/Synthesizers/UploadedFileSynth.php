<?php

namespace Nitro\Livewire\Synthesizers;

use Nitro\Livewire\TemporaryUploadedFile;

/**
 * Carries a pending file upload as component state. The file is stored by its
 * "livewire-file:<name>" token with the client metadata (original name, size,
 * type) in the tuple, so a TemporaryUploadedFile survives every commit until an
 * action moves it into permanent storage.
 */
class UploadedFileSynth implements Synth
{
    public function key(): string
    {
        return 'fil';
    }

    public function match(mixed $value): bool
    {
        return $value instanceof TemporaryUploadedFile;
    }

    /** @param TemporaryUploadedFile $value */
    public function dehydrate(mixed $value): array
    {
        return [$value->toReference(), $value->meta()];
    }

    public function hydrate(mixed $payload, array $meta): mixed
    {
        // $meta still carries the synth key under 's'; drop it before rebuilding.
        unset($meta['s']);

        return new TemporaryUploadedFile(
            substr((string) $payload, strlen(TemporaryUploadedFile::PREFIX)),
            $meta,
        );
    }
}
