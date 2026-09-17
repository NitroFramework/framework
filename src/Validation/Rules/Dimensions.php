<?php

namespace Nitro\Validation\Rules;

use Nitro\Http\UploadedFile;

/**
 * The uploaded image's pixel dimensions satisfy the given constraints.
 *
 * Understands width, height, min_width, min_height, max_width, max_height and
 * ratio. A ratio may be written as a fraction (16/9) or a decimal (1.7778), and
 * is compared with a small tolerance so a 1920x1080 image counts as 16/9.
 *
 * A file that is not an image, or whose dimensions cannot be read, fails.
 *
 * Usage: 'avatar' => 'image|dimensions:min_width=100,ratio=1/1'
 */
class Dimensions extends AbstractRule
{
    /** How far a ratio may deviate and still count as a match. */
    private const RATIO_TOLERANCE = 0.0001;

    public function passes(): bool
    {
        if ($this->isEmpty($this->value)) {
            return true;
        }

        if (! $this->value instanceof UploadedFile || ! $this->value->isValid()) {
            return false;
        }

        $size = @getimagesize($this->value->getPathname());

        if ($size === false) {
            return false;
        }

        [$width, $height] = $size;

        foreach ($this->constraints() as $key => $expected) {
            if (! $this->satisfies($key, $expected, $width, $height)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The rule's parameters as a key => value map.
     *
     * @return array<string, string>
     */
    private function constraints(): array
    {
        $constraints = [];

        foreach ($this->parameters as $parameter) {
            $parts = explode('=', (string) $parameter, 2);

            if (count($parts) === 2) {
                $constraints[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return $constraints;
    }

    private function satisfies(string $key, string $expected, int $width, int $height): bool
    {
        return match ($key) {
            'width'      => $width === (int) $expected,
            'height'     => $height === (int) $expected,
            'min_width'  => $width >= (int) $expected,
            'min_height' => $height >= (int) $expected,
            'max_width'  => $width <= (int) $expected,
            'max_height' => $height <= (int) $expected,
            'ratio'      => $this->matchesRatio($expected, $width, $height),
            default      => true,
        };
    }

    private function matchesRatio(string $expected, int $width, int $height): bool
    {
        if ($height === 0) {
            return false;
        }

        if (str_contains($expected, '/')) {
            [$numerator, $denominator] = array_pad(explode('/', $expected, 2), 2, '1');

            if ((float) $denominator === 0.0) {
                return false;
            }

            $target = (float) $numerator / (float) $denominator;
        } else {
            $target = (float) $expected;
        }

        return abs(($width / $height) - $target) < self::RATIO_TOLERANCE;
    }

    public function message(): string
    {
        return $this->replaceMessage('The {attribute} has invalid image dimensions.');
    }
}
