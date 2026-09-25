<?php

use Illuminate\Support\Facades\Route;
use Nitro\Tests\Fixtures\Package\Acme;

Route::middleware(['web', 'acme'])->group(function () {
    Route::get('/acme', fn () => Acme::greet('package'))->name('acme.home');
    Route::get('/acme/view', fn () => view('acme::hello', ['name' => 'Nitro']));
});
