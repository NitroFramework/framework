<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Nitro\Tests\Fixtures\Classes\InvokableController;
use Nitro\Tests\Fixtures\Classes\TestController;

Route::get('/', [TestController::class, 'index'])->name('home');
Route::get('/plain/{id}', [TestController::class, 'show'])->name('plain');
Route::get('/inject/{id}', [TestController::class, 'inject']);
Route::get('/optional/{a?}', [TestController::class, 'optional']);
Route::get('/defaults/{page}', [TestController::class, 'show'])->defaults('page', 'x');
Route::get('/posts/{post}', [TestController::class, 'post'])->name('posts.show');
Route::get('/slug/{post:slug}', [TestController::class, 'post']);
Route::get('/status/{status}', [TestController::class, 'status']);
Route::get('/numeric/{n}', fn (string $n) => "n={$n}")->whereNumber('n');
Route::get('/invokable', InvokableController::class);
Route::get('/array', fn () => ['a' => 1]);
Route::get('/abort', fn () => abort(403, 'nope'));
Route::get('/teapot', fn () => abort(418));
Route::get('/http-response', [TestController::class, 'httpResponse']);
Route::get('/form', [TestController::class, 'form']);
Route::post('/form', [TestController::class, 'form']);
Route::get('/current', fn (Request $request) => [
    'name' => $request->route()->getName(),
    'router' => Route::currentRouteName(),
    'param' => $request->route('x'),
])->name('current.route');
Route::get('/current/{x}', fn (Request $request) => $request->route('x'));
Route::get('/url', fn () => [
    'route' => route('posts.show', ['post' => 5]),
    'plain' => route('plain', 7, false),
    'action' => action([TestController::class, 'index'], [], false),
]);

// Precedence: an earlier dynamic route wins over a later static one (like Laravel).
Route::get('/order/{any}', fn ($any) => "dynamic:{$any}");
Route::get('/order/static', fn () => 'static');

Route::middleware('header:X-Mw,hello')->get('/mw', fn () => 'mw');
Route::middleware('terminable')->get('/terminable', fn () => 'ok');


Route::withoutMiddleware(Illuminate\Session\Middleware\StartSession::class)->get('/no-session', fn () => 'no session');

Route::domain('{account}.example.com')->group(function () {
    Route::get('/dashboard', fn (string $account) => "account:{$account}");
});

Route::match(['GET', 'POST'], '/match', fn (Request $request) => $request->method());

Route::get('/me', fn (Request $request) => ['user' => $request->user()?->name])->middleware('auth');

Route::fallback(fn () => response('fallback', 404));
