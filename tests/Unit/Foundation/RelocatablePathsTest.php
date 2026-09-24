<?php

namespace Tests\Unit\Foundation;

use Nitro\Foundation\PathRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Pointing a directory somewhere other than where it is assumed to be.
 *
 * Every path derived from the base path and none of them movable is a ceiling,
 * not a convention: an application whose layout is not the conventional one has
 * no way to say so, and a package that keeps its config or its translations
 * elsewhere has nowhere to say it either. What the tests below care about is
 * that a move is respected, that a directory nested in another follows it, and
 * that separating the two afterwards is still possible.
 */
class RelocatablePathsTest extends TestCase
{
    private function paths(): PathRegistry
    {
        return new PathRegistry('/srv/app');
    }

    private function normalise(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    public function test_the_defaults_are_the_conventional_layout(): void
    {
        $paths = $this->paths();

        $this->assertSame('/srv/app', $this->normalise($paths->base()));
        $this->assertSame('/srv/app/app', $this->normalise($paths->app()));
        $this->assertSame('/srv/app/config', $this->normalise($paths->config()));
        $this->assertSame('/srv/app/lang', $this->normalise($paths->lang()));
        $this->assertSame('/srv/app/storage', $this->normalise($paths->storage()));
        $this->assertSame('/srv/app/storage/cache', $this->normalise($paths->cache()));
        $this->assertSame('/srv/app/resources/views', $this->normalise($paths->views()));
        $this->assertSame('/srv/app/bootstrap', $this->normalise($paths->bootstrap()));
    }

    public function test_a_directory_can_be_pointed_elsewhere_relative_to_the_base(): void
    {
        $paths = $this->paths()->useConfig('etc');

        $this->assertSame('/srv/app/etc', $this->normalise($paths->config()));
        $this->assertSame('/srv/app/etc/app.php', $this->normalise($paths->config('app.php')));
    }

    public function test_a_directory_can_be_pointed_at_an_absolute_path(): void
    {
        $paths = $this->paths()->useStorage('/var/lib/nitro');

        $this->assertSame('/var/lib/nitro', $this->normalise($paths->storage()));
    }

    /** Moving the writable directory has to take what lives inside it. */
    public function test_the_cache_follows_storage(): void
    {
        $paths = $this->paths()->useStorage('/var/lib/nitro');

        $this->assertSame('/var/lib/nitro/cache', $this->normalise($paths->cache()));
        $this->assertSame('/var/lib/nitro/cache/config.php', $this->normalise($paths->cachedConfig()));
    }

    /** And separating them afterwards has to still be possible. */
    public function test_the_cache_can_be_moved_away_from_storage(): void
    {
        $paths = $this->paths()
            ->useStorage('/var/lib/nitro')
            ->useCache('/dev/shm/nitro');

        $this->assertSame('/var/lib/nitro', $this->normalise($paths->storage()));
        $this->assertSame('/dev/shm/nitro', $this->normalise($paths->cache()));
        $this->assertSame('/dev/shm/nitro/routes.php', $this->normalise($paths->cachedRoutes()));
    }

    public function test_views_follow_resources_and_can_leave_them(): void
    {
        $following = $this->paths()->useResources('/srv/assets');

        $this->assertSame('/srv/assets/views', $this->normalise($following->views()));

        $separated = $this->paths()->useViews('/srv/templates');

        $this->assertSame('/srv/templates', $this->normalise($separated->views()));
    }

    public function test_migrations_seeders_and_factories_follow_the_database_directory(): void
    {
        $paths = $this->paths()->useDatabase('/srv/db');

        $this->assertSame('/srv/db/migrations', $this->normalise($paths->migrations()));
        $this->assertSame('/srv/db/seeders', $this->normalise($paths->seeders()));
        $this->assertSame('/srv/db/factories', $this->normalise($paths->factories()));
    }

    public function test_moving_the_base_moves_everything_deriving_from_it(): void
    {
        $paths = $this->paths()->useBase('/opt/nitro');

        $this->assertSame('/opt/nitro', $this->normalise($paths->base()));
        $this->assertSame('/opt/nitro/config', $this->normalise($paths->config()));
        $this->assertSame('/opt/nitro/storage/cache', $this->normalise($paths->cache()));
    }

    public function test_use_takes_any_directory_by_name(): void
    {
        $paths = $this->paths()->use('lang', 'translations');

        $this->assertSame('/srv/app/translations', $this->normalise($paths->lang()));
    }

    /** Empty segments must not leave a doubled separator behind. */
    public function test_join_drops_empty_segments(): void
    {
        $paths = $this->paths();

        $this->assertSame('/srv/app/a/b', $this->normalise($paths->join('/srv/app', 'a', 'b')));
        $this->assertSame('/srv/app/a', $this->normalise($paths->join('/srv/app', '', 'a', '')));
        $this->assertSame('/srv/app', $this->normalise($paths->join('/srv/app')));
        $this->assertSame('/srv/app/a/b', $this->normalise($paths->join('/srv/app/', '/a/', '/b/')));
    }

    public function test_every_move_is_chainable(): void
    {
        $paths = $this->paths()
            ->useApp('src')
            ->useConfig('etc')
            ->useLang('i18n')
            ->usePublic('web');

        $this->assertSame('/srv/app/src', $this->normalise($paths->app()));
        $this->assertSame('/srv/app/etc', $this->normalise($paths->config()));
        $this->assertSame('/srv/app/i18n', $this->normalise($paths->lang()));
        $this->assertSame('/srv/app/web', $this->normalise($paths->public()));
    }
}
