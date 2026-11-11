<?php

use App\Http\Controllers\CompanyContextController;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware([StartSession::class, 'auth'])->group(function () {
    Route::post('/company-context', [CompanyContextController::class, 'update'])
        ->name('company-context.update');
});
