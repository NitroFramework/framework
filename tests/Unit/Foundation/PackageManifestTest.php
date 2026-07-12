<?php

namespace Tests\Unit\Foundation;

use Nitro\Foundation\PackageManifest;
use PHPUnit\Framework\TestCase;

class PmAlpha {}
class PmBeta {}

/**
 * Laravel-style package auto-discovery: providers declared under
 * extra.nitro.providers are discovered, dont-discover opts packages out, "*"
 * disables discovery, and the result caches to packages.php.
 */
class PackageManifestTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_' . uniqid();
        mkdir($this->dir . '/vendor/composer', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->dir);
    }

    private function rmrf(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $f) {
                if ($f !== '.' && $f !== '..') {
                    $this->rmrf($path . DIRECTORY_SEPARATOR . $f);
                }
            }
            @rmdir($path);
        } elseif (is_file($path)) {
            @unlink($path);
        }
    }

    private function installed(array $packages): void
    {
        file_put_contents(
            $this->dir . '/vendor/composer/installed.json',
            json_encode(['packages' => $packages])
        );
    }

    private function appComposer(array $extra = []): void
    {
        file_put_contents($this->dir . '/composer.json', json_encode(['extra' => $extra]));
    }

    private function manifest(): PackageManifest
    {
        return new PackageManifest($this->dir . '/vendor', $this->dir, $this->dir . '/cache/packages.php');
    }

    public function test_discovers_providers_from_extra_nitro(): void
    {
        $this->installed([
            ['name' => 'vendor/pkg', 'extra' => ['nitro' => ['providers' => [PmAlpha::class]]]],
            ['name' => 'other/pkg'],
        ]);
        $this->appComposer();

        $this->assertSame([PmAlpha::class], $this->manifest()->providers());
    }

    public function test_dont_discover_at_app_level(): void
    {
        $this->installed([
            ['name' => 'vendor/pkg', 'extra' => ['nitro' => ['providers' => [PmAlpha::class]]]],
        ]);
        $this->appComposer(['nitro' => ['dont-discover' => ['vendor/pkg']]]);

        $this->assertSame([], $this->manifest()->providers());
    }

    public function test_star_disables_all_discovery(): void
    {
        $this->installed([
            ['name' => 'vendor/pkg', 'extra' => ['nitro' => ['providers' => [PmAlpha::class]]]],
            ['name' => 'other/pkg', 'extra' => ['nitro' => ['providers' => [PmBeta::class]]]],
        ]);
        $this->appComposer(['nitro' => ['dont-discover' => ['*']]]);

        $this->assertSame([], $this->manifest()->providers());
    }

    public function test_lazily_caches_to_packages_php(): void
    {
        $this->installed([
            ['name' => 'vendor/pkg', 'extra' => ['nitro' => ['providers' => [PmAlpha::class]]]],
        ]);
        $this->appComposer();

        $cache = $this->dir . '/cache/packages.php';
        $this->assertFileDoesNotExist($cache);
        $this->manifest()->providers();               // triggers lazy build
        $this->assertFileExists($cache);
    }

    public function test_missing_provider_class_is_skipped_not_fatal(): void
    {
        $this->installed([
            ['name' => 'vendor/pkg', 'extra' => ['nitro' => ['providers' => ['Vendor\\Nope\\Missing', PmBeta::class]]]],
        ]);
        $this->appComposer();

        $this->assertSame([PmBeta::class], $this->manifest()->providers());
    }
}
