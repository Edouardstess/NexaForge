<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Support\Http\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
 * Toutes les routes sont sous /api/v1. Le préfixe de version est posé dès la
 * première route : l'ajouter après coup casse tous les clients déjà déployés,
 * et une caisse installée chez un commerçant ne se met pas à jour à la demande.
 */

Route::get('health', fn () => ApiResponse::ok(['status' => 'ok']));

Route::get('ready', function () {
    $checks = [];

    try {
        DB::select('select 1');
        $checks['database'] = 'ok';
    } catch (\Throwable $e) {
        $checks['database'] = 'down';
    }

    $healthy = ! in_array('down', $checks, true);

    return ApiResponse::ok($checks, [], $healthy ? 200 : 503);
});

Route::post('auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:10,1');

Route::middleware('auth.token')->group(function (): void {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::post('auth/logout-everywhere', [AuthController::class, 'logoutEverywhere']);

    Route::middleware('organization')->group(function (): void {
        Route::get('me', [AuthController::class, 'me']);
    });
});
