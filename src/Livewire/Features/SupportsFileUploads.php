<?php

namespace Nitro\Livewire\Features;

use Nitro\Livewire\Hooks\ComponentHook;
use Nitro\Livewire\Support\TemporaryUploadedFile;

/**
 * wire:model on a file input. The browser uploads the file up-front to the
 * upload endpoint and then sends an opaque "livewire-file:<name>" TOKEN as the
 * property's value; this feature is what turns that token back into a
 * {@see TemporaryUploadedFile} on the way in, and what the upload endpoint uses
 * to park the bytes in the temp directory.
 *
 * The promotion happens inside setProperty (see
 * {@see \Nitro\Livewire\Properties\InteractsWithProperties}) so an action always
 * sees a real file object, never the token.
 */
class SupportsFileUploads extends ComponentHook
{
    /** Whether an incoming wire:model value is an upload token (or a list of them). */
    public static function isUpload(mixed $value): bool
    {
        return TemporaryUploadedFile::isUploadToken($value);
    }

    /** Promote an upload token (or list of tokens) to TemporaryUploadedFile(s). */
    public static function promote(mixed $value): mixed
    {
        return TemporaryUploadedFile::fromValue($value);
    }

    /** Absolute path of the directory pending uploads are parked in. */
    public static function temporaryDirectory(): string
    {
        $dir = storage_path('app/' . TemporaryUploadedFile::TMP_DIR);

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /**
     * Move one uploaded file into the temp directory under a random name and
     * write the sidecar carrying its client metadata. Returns the temp name the
     * client sets on the property (as a "livewire-file:" token).
     */
    public static function store(string $dir, string $tmpPath, string $original, int $size, string $type): string
    {
        $extension = pathinfo($original, PATHINFO_EXTENSION);
        $name = bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');

        move_uploaded_file($tmpPath, $dir . '/' . $name);
        file_put_contents(
            $dir . '/' . $name . '.meta.json',
            json_encode(['name' => $original, 'size' => $size, 'type' => $type])
        );

        return $name;
    }
}
