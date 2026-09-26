<?php

/**
 * Runs the whole suite on Nitro's compiled Eloquent Model instead of Laravel's:
 *
 *   vendor/bin/phpunit --bootstrap tests/bootstrap-compiled.php
 *
 * The compiled Model is loaded before any test runs, and every fixture model gets a boot plan,
 * so each test that touches Eloquent exercises the compiled paths.
 */

use Illuminate\Database\Eloquent\Model;
use Nitro\Database\Eloquent\CompiledModels;
use Nitro\Database\Eloquent\ModelCompiler;

require dirname(__DIR__).'/vendor/autoload.php';

$directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nitro-compiled-suite';

if (! is_dir($directory)) {
    mkdir($directory);
}

$laravel = CompiledModels::laravelModelPath();
$model = $directory.DIRECTORY_SEPARATOR.'eloquent-model.php';
$manifest = $directory.DIRECTORY_SEPARATOR.'eloquent.php';

file_put_contents($model, (new ModelCompiler)->compileModel(file_get_contents($laravel)));
file_put_contents($manifest, ModelCompiler::export(CompiledModels::stamp($laravel), []));

CompiledModels::register($model, $manifest);

if (realpath((new ReflectionClass(Model::class))->getFileName()) !== realpath($model)) {
    fwrite(STDERR, "The compiled Model did not load.\n");
    exit(1);
}

/** Plans reflect on the fixture models, which needs Model loaded: the compiled one, by now. */
CompiledModels::usePlans(ModelCompiler::plans([__DIR__.'/Fixtures/Classes', __DIR__.'/Fixtures/Eloquent']));
