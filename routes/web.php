<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/debug-request', function () {
    return [
        'url' => request()->fullUrl(),
        'host' => request()->getHost(),
        'scheme' => request()->getScheme(),
        'isSecure' => request()->isSecure(),
        'headers' => request()->headers->all(),
    ];
});