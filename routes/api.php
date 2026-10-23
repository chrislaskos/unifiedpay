<?php

declare(strict_types=1);

use App\Http\Controllers\ChargeController;
use Illuminate\Support\Facades\Route;

Route::post('/charge', ChargeController::class)
    ->middleware('auth:sanctum');
