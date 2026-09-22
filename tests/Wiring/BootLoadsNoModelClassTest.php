<?php

namespace Tests\Wiring;

use Nitro\Container\Container;
use Nitro\Database\Model\ModelState;
use Nitro\Foundation\Application;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Booting must not load the model layer.
 *
 * DatabaseServiceProvider has to clear the booted-class map and set the event
 * dispatcher before the first model exists. Doing that through Model would
 * load Model and all nine of its Concerns traits — eleven files — on a request
 * that may never query anything, so the two slots live on ModelState instead,
 * which imports one interface and nothing else.
 *
 * That saving is held up by nothing but the absence of a `Model::` somewhere in
 * a provider, which is a one-line mistake to make and produces no symptom
 * beyond a slower boot. Hence this test.
 *
 * It runs in its own process: get_included_files() is per-process, and by the
 * time the rest of the suite has run, half the framework is loaded.
 */
class BootLoadsNoModelClassTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_full_boot_loads_no_model_class_but_the_state_holder(): void
    {
        Container::setInstance(new Container());

        Application::create(dirname(__DIR__, 2))->bootstrap();

        restore_error_handler();
        restore_exception_handler();
        restore_exception_handler();

        $loaded = [];

        foreach (get_included_files() as $file) {
            $path = str_replace('\\', '/', $file);

            if (str_contains($path, '/src/Database/Model/')) {
                $loaded[] = basename($path);
            }
        }

        sort($loaded);

        $this->assertSame(
            ['ModelState.php'],
            $loaded,
            "booting loaded model-layer files it does not need:\n  " . implode("\n  ", $loaded)
                . "\n\nSomething reached for a Model static during boot. Move it to "
                . ModelState::class . ', or give it a holder of its own.',
        );
    }
}
