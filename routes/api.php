<?php

use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| REST API (prefix otomatis /api)
|--------------------------------------------------------------------------
*/
Route::get('/user', UserController::class)->middleware(['auth:api', 'active'])->name('api.user');
