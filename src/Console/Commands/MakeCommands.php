<?php

namespace Nitro\Console\Commands;

use Nitro\Console\ExitCode;
use Nitro\Console\Contracts\CommandInterface;
use Nitro\Console\OutputFormatter;
use Nitro\Foundation\Contracts\PathRegistry;

/**
 * Class generators, Laravel's make:* surface.
 *
 *   make:controller <name> [--resource]   app/Http/Controllers (App\Http\Controllers)
 *   make:model <name>                      app/Models (App\Models)
 *   make:middleware <name>                 app/Http/Middleware (App\Http\Middleware)
 *   make:request <name>                    app/Http/Requests (App\Http\Requests) — FormRequest
 *
 * Names may be nested with "/" or "\" (e.g. make:controller Auth/LoginController
 * → app/Http/Controllers/Auth/LoginController.php, namespace
 * App\Http\Controllers\Auth).
 * A "Controller"/"Middleware" suffix is added when missing, matching Laravel.
 */
class MakeCommands implements CommandInterface
{
    public function __construct(
        private readonly OutputFormatter $output,
        private readonly PathRegistry $paths,
    ) {}

    /**
     * Signature => description, as a constant so the manager can read it
     * without constructing the command.
     *
     * @var array<string, string>
     */
    public const COMMANDS = [
            'make:controller' => 'Create a new controller class (--resource for RESTful stubs)',
            'make:model'      => 'Create a new model class',
            'make:middleware' => 'Create a new middleware class',
            'make:request'    => 'Create a new form request class',
            'make:action'     => 'Create a new single-action class',
            'make:command'    => 'Create a new console command class',
            'make:module'     => 'Scaffold a new self-contained module under app/Modules',
        ];

    public function getCommands(): array
    {
        return self::COMMANDS;
    }

    public function handle(string $command, array $arguments = []): int
    {
        $name = $arguments[0] ?? null;
        if (!$name || str_starts_with($name, '--')) {
            $this->output->error("Usage: {$command} <Name>");
            return ExitCode::FAILURE;
        }

        match ($command) {
            'make:controller' => $this->makeController($name, in_array('--resource', $arguments, true)),
            'make:model'      => $this->makeModel($name),
            'make:middleware' => $this->makeMiddleware($name),
            'make:request'    => $this->makeRequest($name),
            'make:action'     => $this->makeAction($name),
            'make:command'    => $this->makeCommand($name),
            'make:module'     => $this->makeModule($name),
            default           => $this->invalidSignature("Unknown make command: {$command}"),
        };

        return ExitCode::SUCCESS;
    }

    private function makeController(string $name, bool $resource): int
    {
        [$ns, $class, $rel] = $this->resolve($name, 'App\\Http\\Controllers', 'Controller');
        $body = $resource ? $this->resourceMethods() : "    //\n";

        $this->write("app/Http/Controllers/{$rel}", <<<PHP
        <?php

        namespace {$ns};

        use Nitro\\Http\\Controller\\Controller;

        class {$class} extends Controller
        {
        {$body}}

        PHP);

        return ExitCode::SUCCESS;
    }

    private function makeCommand(string $name): int
    {
        [$ns, $class, $rel] = $this->resolve($name, 'App\\Console\\Commands', '');
        $signature = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $class));

        $this->write("app/Console/Commands/{$rel}", <<<PHP
        <?php

        namespace {$ns};

        use Nitro\\Console\\Command;

        class {$class} extends Command
        {
            protected string \$signature = '{$signature}';

            protected string \$description = 'Command description';

            public function handle(): int
            {
                \$this->components->info('{$class} ran.');

                return 0;
            }
        }

        PHP);

        return ExitCode::SUCCESS;
    }

    private function makeModel(string $name): int
    {
        [$ns, $class, $rel] = $this->resolve($name, 'App\\Models', '');

        $this->write("app/Models/{$rel}", <<<PHP
        <?php

        namespace {$ns};

        use Nitro\\Database\\Model\\Model;

        class {$class} extends Model
        {
            protected array \$fillable = [];
        }

        PHP);

        return ExitCode::SUCCESS;
    }

    private function makeMiddleware(string $name): int
    {
        [$ns, $class, $rel] = $this->resolve($name, 'App\\Http\\Middleware', 'Middleware');

        $this->write("app/Http/Middleware/{$rel}", <<<PHP
        <?php

        namespace {$ns};

        use Nitro\\Http\\Request;
        use Nitro\\Http\\Response;

        class {$class}
        {
            /**
             * Handle an incoming request.
             */
            public function handle(Request \$request, callable \$next): Response
            {
                return \$next(\$request);
            }
        }

        PHP);

        return ExitCode::SUCCESS;
    }

    private function makeRequest(string $name): int
    {
        [$ns, $class, $rel] = $this->resolve($name, 'App\\Http\\Requests', 'Request');

        $this->write("app/Http/Requests/{$rel}", <<<PHP
        <?php

        namespace {$ns};

        use Nitro\\Http\\FormRequest;

        class {$class} extends FormRequest
        {
            /**
             * Whether the user is authorized to make this request.
             */
            public function authorize(): bool
            {
                return true;
            }

            /**
             * Validation rules that apply to the request.
             */
            public function rules(): array
            {
                return [
                    //
                ];
            }
        }

        PHP);

        return ExitCode::SUCCESS;
    }

    private function makeAction(string $name): int
    {
        [$ns, $class, $rel] = $this->resolve($name, 'App\\Actions', '');

        $this->write("app/Actions/{$rel}", <<<PHP
        <?php

        namespace {$ns};

        use Nitro\\Actions\\Action;

        class {$class} extends Action
        {
            /**
             * Run the action. Call it as an object with {$class}::run(...),
             * or point a route at it: Route::post('/...', {$class}::class).
             */
            public function handle()
            {
                //
            }
        }

        PHP);

        return ExitCode::SUCCESS;
    }

    /**
     * Scaffold a self-contained module under app/Modules/{Name}.
     *
     * Generates the auto-wiring provider plus routes.php, config.php and a
     * sample namespaced view; the module registers itself on the next request.
     */
    private function makeModule(string $name): int
    {
        $module = ucfirst((string) preg_replace('/[^A-Za-z0-9]/', '', $name));
        if ($module === '') {
            $this->output->error("Invalid module name: {$name}");
            return ExitCode::FAILURE;
        }

        $slug = strtolower($module);
        $base = "app/Modules/{$module}";

        $this->write("{$base}/{$module}ServiceProvider.php", <<<PHP
        <?php

        namespace App\\Modules\\{$module};

        use Nitro\\Foundation\\Providers\\ModuleServiceProvider;

        /**
         * {$module} module service provider.
         *
         * The ModuleServiceProvider base auto-wires routes.php, views/,
         * migrations/ and config.php by convention. Override register()/boot()
         * (calling parent::register()) only for custom bindings.
         */
        class {$module}ServiceProvider extends ModuleServiceProvider
        {
            //
        }

        PHP);

        $this->write("{$base}/routes.php", <<<PHP
        <?php

        use Nitro\\Facades\\Route;

        // Routes for the {$module} module.
        Route::get('/{$slug}', fn () => view('{$slug}::index'));

        PHP);

        $this->write("{$base}/config.php", <<<PHP
        <?php

        // Default {$module} config, merged under config('{$slug}.*').
        return [
            //
        ];

        PHP);

        $this->write("{$base}/views/index.blade.php", <<<BLADE
        <h1>{$module}</h1>
        <p>The {$module} module is working.</p>

        BLADE);

        $this->write("{$base}/migrations/.gitkeep", '');
        $this->write("{$base}/src/.gitkeep", '');

        $this->output->success("Module '{$module}' scaffolded — it auto-registers on the next request.");

        return ExitCode::SUCCESS;
    }

    /**
     * Turn a (possibly nested) name into [namespace, className, relativePath].
     * "Auth/LoginController" + base "App\Controllers" → ["App\Controllers\Auth",
     * "LoginController", "Auth/LoginController.php"].
     */
    private function resolve(string $name, string $baseNamespace, string $suffix): array
    {
        $name = str_replace('\\', '/', trim($name, '/\\'));

        if ($suffix !== '' && !str_ends_with($name, $suffix)) {
            $name .= $suffix;
        }

        $segments = explode('/', $name);
        $class    = array_pop($segments);
        $ns       = $baseNamespace . ($segments ? '\\' . implode('\\', $segments) : '');
        $rel      = ($segments ? implode('/', $segments) . '/' : '') . $class . '.php';

        return [$ns, $class, $rel];
    }

    private function write(string $relativePath, string $contents): void
    {
        $path = $this->paths->base($relativePath);
        $dir  = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        if (file_exists($path)) {
            $this->output->error("File already exists: {$relativePath}");
            return;
        }

        file_put_contents($path, $contents);
        $this->output->success("Created: {$relativePath}");
    }

    private function resourceMethods(): string
    {
        return <<<'PHP'
            public function index() {}

            public function create() {}

            public function store() {}

            public function show($id) {}

            public function edit($id) {}

            public function update($id) {}

            public function destroy($id) {}

        PHP;
    }
    /** Report an unrecognised signature and fail the invocation. */
    private function invalidSignature(string $message): int
    {
        $this->output->error($message);

        return ExitCode::INVALID;
    }
}
