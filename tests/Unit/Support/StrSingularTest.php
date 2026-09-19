<?php

namespace Tests\Unit\Support;

use Nitro\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Str::singular(), the inverse of Str::plural().
 *
 * It exists because the router had rolled its own as rtrim($name, 's'), which
 * strips every trailing s at once: Route::resource('address', …) registered
 * /address/{addre}, and 'status' gave {statu}. Rule-based rather than
 * exhaustive, for the same reason plural() is.
 */
class StrSingularTest extends TestCase
{
    #[DataProvider('words')]
    public function test_it_singularizes(string $plural, string $expected): void
    {
        $this->assertSame($expected, Str::singular($plural));
    }

    public static function words(): array
    {
        return [
            'regular'                => ['users', 'user'],
            'consonant + ies'        => ['categories', 'category'],
            'vowel + s'              => ['days', 'day'],
            'sibilant + es'          => ['boxes', 'box'],
            'double s + es'          => ['classes', 'class'],
            'z + es'                 => ['quizzes', 'quiz'],
            'ch + es'                => ['batches', 'batch'],
            'lves'                   => ['shelves', 'shelf'],
            'ves'                    => ['knives', 'knife'],
            'irregular'              => ['people', 'person'],
            'irregular child'        => ['children', 'child'],
            'irregular analysis'     => ['analyses', 'analysis'],
            'uncountable'            => ['series', 'series'],
            'uncountable news'       => ['news', 'news'],

            /*
             * The cases that made the old heuristic wrong. Each is already
             * singular and must come back untouched.
             */
            'already singular -ss'   => ['address', 'address'],
            'already singular -us'   => ['status', 'status'],
            'already singular -is'   => ['basis', 'basis'],
            'already singular -us 2' => ['campus', 'campus'],

            /* And their real plurals still reduce. */
            'addresses'              => ['addresses', 'address'],
            'statuses'               => ['statuses', 'status'],
        ];
    }

    public function test_it_keeps_the_capitalisation_of_the_original(): void
    {
        $this->assertSame('Post', Str::singular('Posts'));
        $this->assertSame('Category', Str::singular('Categories'));
    }

    /**
     * The property the router actually relies on: a resource name pluralized
     * and singularized again is the word you started with.
     */
    #[DataProvider('roundTrips')]
    public function test_plural_and_singular_are_inverses(string $singular): void
    {
        $this->assertSame($singular, Str::singular(Str::plural($singular)));
    }

    public static function roundTrips(): array
    {
        return array_map(
            static fn (string $word): array => [$word],
            ['user', 'post', 'category', 'company', 'address', 'status', 'class', 'box', 'batch', 'day'],
        );
    }

    public function test_an_empty_string_is_left_alone(): void
    {
        $this->assertSame('', Str::singular(''));
    }
}
