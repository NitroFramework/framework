<?php

namespace Nitro\Livewire;

use RuntimeException;

/**
 * Denylist of classes a synthesizer must never rebuild from a component
 * snapshot. Defense-in-depth: the snapshot is already HMAC-checksum-verified
 * before hydration (see {@see Checksum}), so a synth can't normally be handed a
 * class it wasn't given. This is the second wall — if that first wall ever falls
 * (a leaked APP_KEY lets an attacker forge checksums, or a verify bug), the
 * class-string instantiation sinks in ModelSynth/EnumSynth still can't be turned
 * into known gadget chains (arbitrary command execution, mail/queue abuse, POP
 * gadgets). Mirrors livewire/livewire's SecurityPolicy.
 *
 * Apps extend the list via denyClasses() from a service provider.
 */
class SecurityPolicy
{
    /** @var array<int, class-string|string> */
    protected static array $deniedClasses = [
        // Console commands — could execute arbitrary framework/system commands.
        'Nitro\\Console\\Command',
        'Symfony\\Component\\Console\\Command\\Command',

        // Direct process execution.
        'Symfony\\Component\\Process\\Process',

        // Queue jobs — a hydrated job could execute arbitrary code when run.
        'Nitro\\Queue\\Job',

        // Mail / notifications — could send arbitrary messages.
        'Nitro\\Mail\\Message',
        'Nitro\\Notifications\\Notification',

        // Known third-party serialization/gadget chains (present via composer
        // deps in a typical app). is_a() with a missing class is a safe false.
        'GuzzleHttp\\Psr7\\FnStream',
        'Laravel\\Prompts\\Terminal',
        'Monolog\\Handler\\AbstractProcessingHandler',
    ];

    /**
     * Throw if $class is (or extends/implements) a denied class.
     *
     * @throws RuntimeException when the class is on the denylist.
     */
    public static function validateClass(string $class): void
    {
        foreach (static::$deniedClasses as $denied) {
            // The `true` arg lets us test a class-string without instantiating;
            // a non-existent $denied simply yields false.
            if (is_a($class, $denied, true)) {
                throw new RuntimeException(
                    "Livewire: class [{$class}] is not allowed to be hydrated from a component snapshot."
                );
            }
        }
    }

    /** Add classes to the denylist at runtime (e.g. from a service provider). */
    public static function denyClasses(array $classes): void
    {
        static::$deniedClasses = array_values(array_unique(
            array_merge(static::$deniedClasses, $classes)
        ));
    }

    /** @return array<int, string> The current denylist (introspection/tests). */
    public static function deniedClasses(): array
    {
        return static::$deniedClasses;
    }
}
