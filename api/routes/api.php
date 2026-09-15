<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CashierSessionController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ExchangeRateController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\OrderController;
use App\Support\Http\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
 * Tout est sous /api/v1. Le préfixe de version est posé dès la première
 * route : l'ajouter après coup casse les clients déjà déployés, et une caisse
 * installée chez un commerçant ne se met pas à jour à la demande.
 *
 * Chaque route nomme la permission qu'elle exige. Jamais un rôle : un rôle
 * est un modèle de permissions, pas une identité de contrôle.          [D-03]
 */

Route::get('health', fn () => ApiResponse::ok(['status' => 'ok']));

Route::get('ready', function () {
    $checks = [];

    try {
        DB::select('select 1');
        $checks['database'] = 'ok';
    } catch (\Throwable) {
        $checks['database'] = 'down';
    }

    return ApiResponse::ok($checks, [], in_array('down', $checks, true) ? 503 : 200);
});

Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth.token')->group(function (): void {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::post('auth/logout-everywhere', [AuthController::class, 'logoutEverywhere']);

    Route::middleware('organization')->group(function (): void {
        Route::get('me', [AuthController::class, 'me']);

        /* ---------- catalogue ---------- */
        Route::get('products', [CatalogController::class, 'index'])
            ->middleware('can.do:product.read');
        Route::post('products', [CatalogController::class, 'store'])
            ->middleware('can.do:product.create');
        // Le catalogue complet d'un point de vente, prix et stock inclus :
        // ce que la caisse télécharge avant de passer hors-ligne.
        Route::get('catalog/snapshot', [CatalogController::class, 'snapshot'])
            ->middleware('can.do:product.read');
        Route::post('variants/{variant}/price', [CatalogController::class, 'setPrice'])
            ->middleware('can.do:price.update');

        /* ---------- taux de change ---------- */
        Route::get('exchange-rates', [ExchangeRateController::class, 'index'])
            ->middleware('can.do:price.read');
        Route::post('exchange-rates', [ExchangeRateController::class, 'store'])
            ->middleware('can.do:exchange_rate.update');

        /* ---------- stock ---------- */
        Route::get('inventory', [InventoryController::class, 'index'])
            ->middleware('can.do:inventory.read');
        Route::get('inventory/movements', [InventoryController::class, 'movements'])
            ->middleware('can.do:inventory.read');
        Route::get('inventory/discrepancies', [InventoryController::class, 'discrepancies'])
            ->middleware('can.do:conflict.read');
        Route::post('inventory/receipts', [InventoryController::class, 'receive'])
            ->middleware(['can.do:inventory.receive', 'idempotent']);
        Route::post('inventory/adjustments', [InventoryController::class, 'adjust'])
            ->middleware(['can.do:inventory.adjust', 'idempotent']);

        /* ---------- caisse ---------- */
        Route::get('cashier-sessions/current', [CashierSessionController::class, 'current'])
            ->middleware('can.do:session.read');
        Route::post('cashier-sessions', [CashierSessionController::class, 'open'])
            ->middleware('can.do:session.open');
        Route::post('cashier-sessions/{session}/close', [CashierSessionController::class, 'close'])
            ->middleware('can.do:session.close');
        Route::get('cashier-sessions/{session}/report', [CashierSessionController::class, 'report'])
            ->middleware('can.do:session.read');

        /* ---------- ventes ---------- */
        Route::get('orders', [OrderController::class, 'index'])
            ->middleware('can.do:order.read');
        Route::get('orders/{order}', [OrderController::class, 'show'])
            ->middleware('can.do:order.read');
        // Idempotence obligatoire : une caisse qui perd le réseau réessaie,
        // et le client ne doit pas être débité deux fois.              [D-11]
        Route::post('orders', [OrderController::class, 'store'])
            ->middleware(['can.do:order.create', 'idempotent']);
    });
});
