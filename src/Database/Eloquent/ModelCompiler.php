<?php

namespace Nitro\Database\Eloquent;

use Composer\Autoload\ClassLoader;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Attributes\Initialize;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasEvents;
use Illuminate\Database\Eloquent\Concerns\HasGlobalScopes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use PhpToken;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Finder\Finder;
use Throwable;
use UnitEnum;

/**
 * Compiles Eloquent for `php artisan eloquent:cache`:
 *
 *  - a copy of Laravel's Model (bootstrap/cache/eloquent-model.php) that reads what never
 *    changes for a class from Nitro's plan instead of reflecting on every request:
 *    bootTraits() takes the boot methods and initializers, resolveClassAttribute() the class
 *    attributes (#[Table], #[Fillable], ...), and its overrides of the HasEvents /
 *    HasGlobalScopes trait methods resolveObserveAttributes() / resolveGlobalScopeAttributes()
 *    the #[ObservedBy] / #[ScopedBy] lists; initializeModelAttributes() looks up the class's
 *    table declaration once per class instead of once per instance;
 *  - the plan of every model in the app (bootstrap/cache/eloquent.php), worked out with
 *    Laravel's own algorithms.
 *
 * A method is replaced (or a trait method overridden) only when it is exactly the method Nitro
 * was written against; otherwise that change is skipped and Laravel's method is kept. Models
 * without a plan (added since the last optimize, or outside the configured paths), and models
 * that override one of these methods themselves, go through Laravel's own code.
 */
final class ModelCompiler
{
    private const BOOT_TRAITS = <<<'PHP'
    protected static function bootTraits()
    {
        $class = static::class;

        $booted = [];

        static::$traitInitializers[$class] = [];

        $uses = class_uses_recursive($class);

        $conventionalBootMethods = array_map(static fn ($trait) => 'boot'.class_basename($trait), $uses);
        $conventionalInitMethods = array_map(static fn ($trait) => 'initialize'.class_basename($trait), $uses);

        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            if (! in_array($method->getName(), $booted) &&
                $method->isStatic() &&
                (in_array($method->getName(), $conventionalBootMethods) ||
                $method->getAttributes(Boot::class) !== [])) {
                $method->invoke(null);

                $booted[] = $method->getName();
            }

            if (in_array($method->getName(), $conventionalInitMethods) ||
                $method->getAttributes(Initialize::class) !== []) {
                static::$traitInitializers[$class][] = $method->getName();
            }
        }

        static::$traitInitializers[$class] = array_unique(static::$traitInitializers[$class]);
    }
PHP;

    private const BOOT_TRAITS_COMPILED = <<<'PHP'
    protected static function bootTraits()
    {
        $class = static::class;

        if (\Nitro\Database\Eloquent\CompiledModels::bootTraits($class, static::$traitInitializers)) {
            return;
        }

        $booted = [];

        static::$traitInitializers[$class] = [];

        $uses = class_uses_recursive($class);

        $conventionalBootMethods = array_map(static fn ($trait) => 'boot'.class_basename($trait), $uses);
        $conventionalInitMethods = array_map(static fn ($trait) => 'initialize'.class_basename($trait), $uses);

        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            if (! in_array($method->getName(), $booted) &&
                $method->isStatic() &&
                (in_array($method->getName(), $conventionalBootMethods) ||
                $method->getAttributes(Boot::class) !== [])) {
                $method->invoke(null);

                $booted[] = $method->getName();
            }

            if (in_array($method->getName(), $conventionalInitMethods) ||
                $method->getAttributes(Initialize::class) !== []) {
                static::$traitInitializers[$class][] = $method->getName();
            }
        }

        static::$traitInitializers[$class] = array_unique(static::$traitInitializers[$class]);
    }
PHP;

    private const INITIALIZE_MODEL_ATTRIBUTES = <<<'PHP'
    public function initializeModelAttributes()
    {
        $table = static::resolveClassAttribute(Table::class);

        $reflection = new ReflectionClass(static::class);

        $declaresTable = $reflection->hasProperty('table')
            && $reflection->getProperty('table')->getDeclaringClass()->getName() === static::class;

        if (! $declaresTable && $reflection->getAttributes(Table::class) !== []) {
            $this->table = $table->name ?? null;
        } else {
            $this->table ??= $table->name ?? null;
        }
PHP;

    private const INITIALIZE_MODEL_ATTRIBUTES_COMPILED = <<<'PHP'
    public function initializeModelAttributes()
    {
        $table = static::resolveClassAttribute(Table::class);

        [$declaresTable, $hasTableAttribute] = \Nitro\Database\Eloquent\CompiledModels::tableDeclaration(static::class);

        if (! $declaresTable && $hasTableAttribute) {
            $this->table = $table->name ?? null;
        } else {
            $this->table ??= $table->name ?? null;
        }
PHP;

    private const RESOLVE_CLASS_ATTRIBUTE = <<<'PHP'
    protected static function resolveClassAttribute(string $attributeClass, ?string $property = null, ?string $class = null)
    {
        $class ??= static::class;

        $cacheKey = $class.'@'.$attributeClass.'@'.$property;

        if (array_key_exists($cacheKey, static::$classAttributes)) {
            return static::$classAttributes[$cacheKey];
        }

        try {
PHP;

    private const RESOLVE_CLASS_ATTRIBUTE_COMPILED = <<<'PHP'
    protected static function resolveClassAttribute(string $attributeClass, ?string $property = null, ?string $class = null)
    {
        $class ??= static::class;

        $cacheKey = $class.'@'.$attributeClass.'@'.$property;

        if (array_key_exists($cacheKey, static::$classAttributes)) {
            return static::$classAttributes[$cacheKey];
        }

        if (($instance = \Nitro\Database\Eloquent\CompiledModels::classAttribute($class, $attributeClass)) !== false) {
            return static::$classAttributes[$cacheKey] = $instance === null ? null : ($property ? $instance->{$property} : $instance);
        }

        try {
PHP;

    private const RESOLVE_OBSERVE_ATTRIBUTES = <<<'PHP'
    public static function resolveObserveAttributes()
    {
        $reflectionClass = new ReflectionClass(static::class);

        $isEloquentGrandchild = is_subclass_of(static::class, Model::class)
            && get_parent_class(static::class) !== Model::class;

        return (new Collection($reflectionClass->getAttributes(ObservedBy::class)))
            ->map(fn ($attribute) => $attribute->getArguments())
            ->flatten()
            ->when($isEloquentGrandchild, function (Collection $attributes) {
                return (new Collection(get_parent_class(static::class)::resolveObserveAttributes()))
                    ->merge($attributes);
            })
            ->all();
    }
PHP;

    private const RESOLVE_OBSERVE_ATTRIBUTES_COMPILED = <<<'PHP'
    /**
     * HasEvents::resolveObserveAttributes(), from Nitro's compiled plan when the class has one.
     */
    public static function resolveObserveAttributes()
    {
        if (($observers = \Nitro\Database\Eloquent\CompiledModels::observers(static::class)) !== null) {
            return $observers;
        }

        $reflectionClass = new \ReflectionClass(static::class);

        $isEloquentGrandchild = is_subclass_of(static::class, \Illuminate\Database\Eloquent\Model::class)
            && get_parent_class(static::class) !== \Illuminate\Database\Eloquent\Model::class;

        return (new \Illuminate\Support\Collection($reflectionClass->getAttributes(\Illuminate\Database\Eloquent\Attributes\ObservedBy::class)))
            ->map(fn ($attribute) => $attribute->getArguments())
            ->flatten()
            ->when($isEloquentGrandchild, function (\Illuminate\Support\Collection $attributes) {
                return (new \Illuminate\Support\Collection(get_parent_class(static::class)::resolveObserveAttributes()))
                    ->merge($attributes);
            })
            ->all();
    }
PHP;

    private const RESOLVE_GLOBAL_SCOPE_ATTRIBUTES = <<<'PHP'
    public static function resolveGlobalScopeAttributes()
    {
        $reflectionClass = new ReflectionClass(static::class);

        $attributes = (new Collection($reflectionClass->getAttributes(ScopedBy::class, ReflectionAttribute::IS_INSTANCEOF)));

        foreach ($reflectionClass->getTraits() as $trait) {
            $attributes->push(...$trait->getAttributes(ScopedBy::class, ReflectionAttribute::IS_INSTANCEOF));
        }

        $isEloquentGrandchild = is_subclass_of(static::class, Model::class)
            && get_parent_class(static::class) !== Model::class;

        return $attributes->map(fn ($attribute) => $attribute->getArguments())
            ->flatten()
            ->when($isEloquentGrandchild, function (Collection $attributes) {
                return (new Collection(get_parent_class(static::class)::resolveGlobalScopeAttributes()))
                    ->merge($attributes);
            })
            ->all();
    }
PHP;

    private const RESOLVE_GLOBAL_SCOPE_ATTRIBUTES_COMPILED = <<<'PHP'
    /**
     * HasGlobalScopes::resolveGlobalScopeAttributes(), from Nitro's compiled plan when the class
     * has one.
     */
    public static function resolveGlobalScopeAttributes()
    {
        if (($scopes = \Nitro\Database\Eloquent\CompiledModels::scopes(static::class)) !== null) {
            return $scopes;
        }

        $reflectionClass = new \ReflectionClass(static::class);

        $attributes = (new \Illuminate\Support\Collection($reflectionClass->getAttributes(\Illuminate\Database\Eloquent\Attributes\ScopedBy::class, \ReflectionAttribute::IS_INSTANCEOF)));

        foreach ($reflectionClass->getTraits() as $trait) {
            $attributes->push(...$trait->getAttributes(\Illuminate\Database\Eloquent\Attributes\ScopedBy::class, \ReflectionAttribute::IS_INSTANCEOF));
        }

        $isEloquentGrandchild = is_subclass_of(static::class, \Illuminate\Database\Eloquent\Model::class)
            && get_parent_class(static::class) !== \Illuminate\Database\Eloquent\Model::class;

        return $attributes->map(fn ($attribute) => $attribute->getArguments())
            ->flatten()
            ->when($isEloquentGrandchild, function (\Illuminate\Support\Collection $attributes) {
                return (new \Illuminate\Support\Collection(get_parent_class(static::class)::resolveGlobalScopeAttributes()))
                    ->merge($attributes);
            })
            ->all();
    }
PHP;

    /**
     * Trait methods the compiled Model overrides (a class's own method wins over its trait's):
     * change => [trait, the trait's method as Laravel ships it, the compiled Model's method].
     *
     * @var array<string, array{0: class-string, 1: string, 2: string}>
     */
    private const OVERRIDES = [
        'resolveObserveAttributes' => [HasEvents::class, self::RESOLVE_OBSERVE_ATTRIBUTES, self::RESOLVE_OBSERVE_ATTRIBUTES_COMPILED],
        'resolveGlobalScopeAttributes' => [HasGlobalScopes::class, self::RESOLVE_GLOBAL_SCOPE_ATTRIBUTES, self::RESOLVE_GLOBAL_SCOPE_ATTRIBUTES_COMPILED],
    ];

    /** @var array<string, array{0: string, 1: string}> change => [Laravel's code, compiled code] */
    private const CHANGES = [
        'bootTraits' => [self::BOOT_TRAITS, self::BOOT_TRAITS_COMPILED],
        'initializeModelAttributes' => [self::INITIALIZE_MODEL_ATTRIBUTES, self::INITIALIZE_MODEL_ATTRIBUTES_COMPILED],
        'resolveClassAttribute' => [self::RESOLVE_CLASS_ATTRIBUTE, self::RESOLVE_CLASS_ATTRIBUTE_COMPILED],
    ];

    /** @var list<string> */
    public array $applied = [];

    /** @var list<string> */
    public array $skipped = [];

    /**
     * Laravel's Model source with every change that matches applied, or null when none does.
     *
     * @param  array<class-string, string>|null  $traits  the trait sources the overrides are checked
     *                                                    against (by default, the installed ones)
     */
    public function compileModel(string $source, ?array $traits = null): ?string
    {
        $eol = str_contains($source, "\r\n") ? "\r\n" : "\n";
        $source = str_replace("\r\n", "\n", $source);

        foreach (self::CHANGES as $change => [$laravel, $compiled]) {
            if (substr_count($source, $laravel) === 1) {
                $source = str_replace($laravel, $compiled, $source);
                $this->applied[] = $change;
            } else {
                $this->skipped[] = $change;
            }
        }

        /** Trait overrides go only into a source recognised as Laravel's Model. */
        if ($this->applied === []) {
            return null;
        }

        $overrides = [];

        foreach (self::OVERRIDES as $change => [$trait, $laravel, $compiled]) {
            $traitSource = str_replace("\r\n", "\n", $traits[$trait] ?? self::installedSource($trait) ?? '');

            if (substr_count($traitSource, $laravel) === 1 && ! str_contains($source, "function {$change}(")) {
                $overrides[] = $compiled;
                $this->applied[] = $change;
            } else {
                $this->skipped[] = $change;
            }
        }

        if ($overrides !== [] && str_ends_with($source, "\n}\n")) {
            $source = substr($source, 0, -2)."\n".implode("\n\n", $overrides)."\n}\n";
        }

        if ($this->applied === [] || ! str_starts_with($source, "<?php\n")) {
            return null;
        }

        $source = "<?php\n\n// Generated by `php artisan eloquent:cache` (Nitro) from Laravel's Model. Do not edit.\n"
            .substr($source, strlen("<?php\n"));

        return str_replace("\n", $eol, $source);
    }

    /**
     * One model's plan: what Model::bootTraits() would do, without invoking anything (the static
     * methods it would call, in order, and the initializers it would record, keys as array_unique()
     * leaves them), the class attributes Model::resolveClassAttribute() would find, and the
     * observers and global scopes its #[ObservedBy] / #[ScopedBy] attributes name.
     *
     * @param  class-string<Model>  $class
     * @return array{0: list<string>, 1: array<int, string>, 2: array<string, array{0: string, 1: array}>|null, 3: list<mixed>|null, 4: list<mixed>|null}
     */
    public static function plan(string $class): array
    {
        $booted = [];
        $initializers = [];

        $uses = class_uses_recursive($class);

        $conventionalBootMethods = array_map(static fn ($trait) => 'boot'.class_basename($trait), $uses);
        $conventionalInitMethods = array_map(static fn ($trait) => 'initialize'.class_basename($trait), $uses);

        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            if (! in_array($method->getName(), $booted) &&
                $method->isStatic() &&
                (in_array($method->getName(), $conventionalBootMethods) ||
                $method->getAttributes(Boot::class) !== [])) {
                $booted[] = $method->getName();
            }

            if (in_array($method->getName(), $conventionalInitMethods) ||
                $method->getAttributes(Initialize::class) !== []) {
                $initializers[] = $method->getName();
            }
        }

        return [
            $booted,
            array_unique($initializers),
            self::classAttributes($class),
            self::resolved($class, 'resolveObserveAttributes', self::observers(...)),
            self::resolved($class, 'resolveGlobalScopeAttributes', self::scopes(...)),
        ];
    }

    /**
     * What $method returns for $class, worked out here by the same algorithm, when it is Laravel's
     * method (not overridden by the model or a parent) and returns plain data; otherwise null, and
     * the method runs as Laravel's at runtime.
     *
     * @param  callable(string): array  $resolve
     */
    private static function resolved(string $class, string $method, callable $resolve): ?array
    {
        try {
            if ((new ReflectionMethod($class, $method))->getDeclaringClass()->getName() !== Model::class) {
                return null;
            }

            $resolved = $resolve($class);
        } catch (Throwable) {
            return null;
        }

        return self::isPlainData($resolved) ? $resolved : null;
    }

    /**
     * HasEvents::resolveObserveAttributes() for $class.
     */
    private static function observers(string $class): array
    {
        $reflectionClass = new ReflectionClass($class);

        $isEloquentGrandchild = is_subclass_of($class, Model::class)
            && get_parent_class($class) !== Model::class;

        return (new Collection($reflectionClass->getAttributes(ObservedBy::class)))
            ->map(fn ($attribute) => $attribute->getArguments())
            ->flatten()
            ->when($isEloquentGrandchild, function (Collection $attributes) use ($class) {
                return (new Collection(self::observers(get_parent_class($class))))
                    ->merge($attributes);
            })
            ->all();
    }

    /**
     * HasGlobalScopes::resolveGlobalScopeAttributes() for $class.
     */
    private static function scopes(string $class): array
    {
        $reflectionClass = new ReflectionClass($class);

        $attributes = (new Collection($reflectionClass->getAttributes(ScopedBy::class, ReflectionAttribute::IS_INSTANCEOF)));

        foreach ($reflectionClass->getTraits() as $trait) {
            $attributes->push(...$trait->getAttributes(ScopedBy::class, ReflectionAttribute::IS_INSTANCEOF));
        }

        $isEloquentGrandchild = is_subclass_of($class, Model::class)
            && get_parent_class($class) !== Model::class;

        return $attributes->map(fn ($attribute) => $attribute->getArguments())
            ->flatten()
            ->when($isEloquentGrandchild, function (Collection $attributes) use ($class) {
                return (new Collection(self::scopes(get_parent_class($class))))
                    ->merge($attributes);
            })
            ->all();
    }

    /**
     * The installed source of a class, as Composer would load it.
     */
    private static function installedSource(string $class): ?string
    {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            if (is_string($file = $loader->findFile($class))) {
                return file_get_contents($file) ?: null;
            }
        }

        return null;
    }

    /**
     * Every class attribute Model::resolveClassAttribute() can find for this class, by lowercased
     * name (it matches names case-insensitively): the first match searching the class, then its
     * traits in order, then the same for each parent. Each is kept as its name and constructor
     * arguments, built at runtime exactly as ReflectionAttribute::newInstance() builds it.
     *
     * Null (resolve through Laravel's code) when an argument is not plain data (an object made
     * with `new`, a closure) or an attribute cannot be instantiated here.
     *
     * @return array<string, array{0: string, 1: array}>|null
     */
    public static function classAttributes(string $class): ?array
    {
        $found = [];

        try {
            $reflection = new ReflectionClass($class);

            do {
                foreach ([$reflection, ...$reflection->getTraits()] as $declaring) {
                    foreach ($declaring->getAttributes() as $attribute) {
                        $found[strtolower($attribute->getName())] ??= $attribute;
                    }
                }
            } while ($reflection = $reflection->getParentClass());

            $attributes = [];

            foreach ($found as $key => $attribute) {
                $arguments = $attribute->getArguments();

                if (! self::isPlainData($arguments)) {
                    return null;
                }

                $attribute->newInstance();
                $attributes[$key] = [$attribute->getName(), $arguments];
            }
        } catch (Throwable) {
            return null;
        }

        return $attributes;
    }

    private static function isPlainData(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::isPlainData($item)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_scalar($value) || $value instanceof UnitEnum;
    }

    /**
     * Boot plans for the concrete models defined under the given directories.
     *
     * @param  list<string>  $paths
     * @return array<class-string<Model>, array{0: list<string>, 1: array<int, string>}>
     */
    public static function plans(array $paths): array
    {
        $plans = [];

        foreach (self::classesIn($paths) as $class) {
            try {
                if (! class_exists($class) || ! is_subclass_of($class, Model::class)
                    || (new ReflectionClass($class))->isAbstract()) {
                    continue;
                }
            } catch (Throwable) {
                continue;
            }

            $plans[$class] = self::plan($class);
        }

        ksort($plans);

        return $plans;
    }

    /**
     * The classes declared in the PHP files under the given directories, read from each file's
     * namespace and class declaration.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private static function classesIn(array $paths): array
    {
        $paths = array_values(array_filter($paths, 'is_dir'));

        if ($paths === []) {
            return [];
        }

        $classes = [];

        foreach (Finder::create()->files()->name('*.php')->in($paths) as $file) {
            if (($class = self::classIn($file->getContents())) !== null) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }

    private static function classIn(string $source): ?string
    {
        $namespace = '';
        $tokens = PhpToken::tokenize($source);

        foreach ($tokens as $i => $token) {
            if ($token->is(T_NAMESPACE)) {
                $namespace = '';

                for ($j = $i + 1; isset($tokens[$j]) && ! $tokens[$j]->is([';', '{']); $j++) {
                    if ($tokens[$j]->is([T_NAME_QUALIFIED, T_STRING])) {
                        $namespace .= $tokens[$j]->text;
                    }
                }
            }

            if ($token->is(T_CLASS) && ! ($tokens[$i - 1] ?? null)?->is(T_DOUBLE_COLON)) {
                for ($j = $i + 1; isset($tokens[$j]); $j++) {
                    if ($tokens[$j]->is(T_STRING)) {
                        return ltrim($namespace.'\\'.$tokens[$j]->text, '\\');
                    }

                    if (! $tokens[$j]->is(T_WHITESPACE)) {
                        break;
                    }
                }
            }
        }

        return null;
    }

    /**
     * The manifest read at runtime: which Laravel Model the compiled copy was made from, and the
     * boot plans.
     */
    public static function export(array $stamp, array $plans): string
    {
        return "<?php\n\n// Generated by `php artisan eloquent:cache` (Nitro). Do not edit.\n\nreturn "
            .var_export(['laravel' => $stamp, 'plans' => $plans], true).";\n";
    }
}
