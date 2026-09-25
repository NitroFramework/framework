<?php

use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => ['pong' => true])->name('api.ping');
