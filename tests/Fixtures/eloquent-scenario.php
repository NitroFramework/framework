<?php

/**
 * Boots the fixture models in a fresh process and prints, as JSON, everything booting them
 * observably did. Run with no arguments it uses Laravel's Model; with a compiled Model and
 * manifest it loads Nitro's compiled Model the way Bootstrap does.
 *
 *   php eloquent-scenario.php [compiled-model.php manifest.php]
 */

use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Nitro\Database\Eloquent\CompiledModels;
use Nitro\Tests\Fixtures\Eloquent\Article;
use Nitro\Tests\Fixtures\Eloquent\BootLog;
use Nitro\Tests\Fixtures\Eloquent\Comment;
use Nitro\Tests\Fixtures\Eloquent\Note;
use Nitro\Tests\Fixtures\Eloquent\Page;
use Nitro\Tests\Fixtures\Eloquent\Plain;
use Nitro\Tests\Fixtures\Eloquent\Tag;
use Nitro\Tests\Fixtures\Eloquent\Task;
use Nitro\Tests\Fixtures\Eloquent\Ticket;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if (isset($argv[1], $argv[2])) {
    CompiledModels::register($argv[1], $argv[2]);

    /** --cleared: the caches are removed after boot, before Model is first used. */
    if (in_array('--cleared', $argv, true)) {
        unlink($argv[1]);
        unlink($argv[2]);
    }
}

$events = new Dispatcher;
$events->listen('eloquent.*', static function (string $event) {
    if (preg_match('/^eloquent\.(booting|booted): (.+)$/', $event, $m)) {
        BootLog::record('event '.$m[1], $m[2]);
    }
});

Model::setEventDispatcher($events);

$modelStatics = static fn (string $property) => (static fn () => static::${$property})->bindTo(null, Model::class)();

$observed = [];

foreach ([Article::class, Comment::class, Page::class, Note::class, Task::class, Ticket::class, Plain::class, Article::class] as $class) {
    BootLog::$calls = [];

    $first = new $class;
    $second = new $class;
    $hydrated = $first->newFromBuilder(['id' => 7, 'deleted_at' => null]);

    $observed[] = [
        'class' => $class,
        'calls' => BootLog::$calls,
        'traitInitializers' => $modelStatics('traitInitializers')[$class] ?? null,
        'booted' => isset($modelStatics('booted')[$class]),
        'globalScopes' => array_keys(Model::getAllGlobalScopes()[$class] ?? []),
        'listeners' => array_map(
            static fn (array $listeners) => array_map(static fn ($listener) => is_string($listener) ? $listener : 'closure', $listeners),
            array_filter($events->getRawListeners(), static fn (string $event) => str_ends_with($event, ': '.$class), ARRAY_FILTER_USE_KEY)
        ),
        'model' => [
            $first->getTable(), $first->getKeyName(), $first->getKeyType(), $first->getIncrementing(),
            $first->getConnectionName(), $first->getCasts(), $second->getTable(),
            $hydrated->exists, $hydrated->getAttributes(), $hydrated->getTable(),
        ],
        'attributes' => [
            $first->getFillable(), $first->getGuarded(), $first->isUnguarded(), $first->getHidden(),
            $first->getVisible(), $first->getAppends(), $first->getTouchedRelations(),
            $first->usesTimestamps(), $first->getRouteKeyName(),
            (fn () => [$this->dateFormat, $this->refreshes, $this->resolveCustomBuilderClass()])->call($first),
            serialize((static fn () => static::resolveClassAttribute(Tag::class, 'value'))->bindTo(null, $class)()),
            serialize((static fn () => static::resolveClassAttribute(Tag::class))->bindTo(null, $class)()),
        ],
    ];
}

echo json_encode([
    'observed' => $observed,
    'model' => (new ReflectionClass(Model::class))->getFileName(),
    'planned' => array_keys($plans = (static fn () => self::$plans)->bindTo(null, CompiledModels::class)()),
    'attributesCompiled' => array_keys(array_filter($plans, static fn ($plan) => ($plan[2] ?? null) !== null)),
]);
