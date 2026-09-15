<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Inventory\StockService;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\OrgContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class InventoryController
{
    public function __construct(
        private readonly OrgContext $context,
        private readonly StockService $stock,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'location_id' => ['required', 'uuid'],
            'low_only' => ['boolean'],
        ]);

        if (! $this->context->canAccessLocation($input['location_id'])) {
            return ApiResponse::error('NOT_FOUND', 'Point de vente introuvable.', 404);
        }

        $rows = DB::table('stock_levels as s')
            ->join('product_variants as v', 'v.id', '=', 's.product_variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('s.organization_id', $this->context->organizationId())
            ->where('s.location_id', $input['location_id'])
            ->when($request->boolean('low_only'), fn ($q) => $q->where('s.quantity', '<=', 5))
            ->orderBy('s.quantity')
            ->select(['s.product_variant_id', 's.quantity', 's.reserved', 's.updated_at',
                      'v.sku', 'v.name as variant_name', 'p.name as product_name'])
            ->get();

        return ApiResponse::ok($rows->map(fn ($r): array => [
            'variant_id' => $r->product_variant_id,
            'sku' => $r->sku,
            'name' => $r->product_name.' — '.$r->variant_name,
            'quantity' => (string) $r->quantity,
            'reserved' => (string) $r->reserved,
            // Un solde négatif n'est pas masqué : c'est l'information exacte
            // que le compte physique ne correspond plus au théorique.  [D-09]
            'negative' => (float) $r->quantity < 0,
            'updated_at' => $r->updated_at,
        ])->all());
    }

    /** Réception fournisseur : c'est elle qui fixe le coût d'achat. */
    public function receive(Request $request): JsonResponse
    {
        $input = $request->validate([
            'location_id' => ['required', 'uuid'],
            'supplier_id' => ['nullable', 'uuid'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.variant_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'string', 'regex:/^\d+(\.\d{1,4})?$/'],
            'lines.*.unit_cost_minor' => ['nullable', 'integer', 'min:0'],
        ]);

        if (! $this->context->canAccessLocation($input['location_id'])) {
            return ApiResponse::error('NOT_FOUND', 'Point de vente introuvable.', 404);
        }

        $result = DB::transaction(function () use ($input): array {
            $out = [];

            foreach ($input['lines'] as $line) {
                $variant = \App\Models\ProductVariant::query()->findOrFail($line['variant_id']);

                // Recevoir 5 caisses de 24, c'est entrer 120 unités. [D-06]
                $quantity = $variant->toStockQuantity($line['quantity']);

                $out[] = [
                    'variant_id' => $variant->id,
                    'received' => $line['quantity'],
                    'stock_quantity' => $quantity,
                    'balance' => $this->stock->move(
                        locationId: $input['location_id'],
                        variantId: $variant->stockVariantId(),
                        type: 'PURCHASE',
                        quantity: $quantity,
                        referenceType: 'supplier',
                        referenceId: $input['supplier_id'] ?? null,
                        unitCostMinor: $line['unit_cost_minor'] ?? null,
                        costCurrency: $this->context->baseCurrency(),
                    ),
                ];
            }

            return $out;
        });

        return ApiResponse::created($result);
    }

    /** Ajustement manuel : il exige toujours une raison écrite. */
    public function adjust(Request $request): JsonResponse
    {
        $input = $request->validate([
            'location_id' => ['required', 'uuid'],
            'variant_id' => ['required', 'uuid'],
            'quantity' => ['required', 'string', 'regex:/^-?\d+(\.\d{1,4})?$/'],
            'reason' => ['required', 'string', 'min:3', 'max:200'],
            'type' => ['nullable', 'in:ADJUSTMENT,WASTE,COUNT'],
        ]);

        if (! $this->context->canAccessLocation($input['location_id'])) {
            return ApiResponse::error('NOT_FOUND', 'Point de vente introuvable.', 404);
        }

        $balance = DB::transaction(fn (): string => $this->stock->move(
            locationId: $input['location_id'],
            variantId: $input['variant_id'],
            type: $input['type'] ?? 'ADJUSTMENT',
            quantity: $input['quantity'],
            reason: $input['reason'],
        ));

        return ApiResponse::created([
            'variant_id' => $input['variant_id'],
            'balance' => $balance,
        ]);
    }

    public function movements(Request $request): JsonResponse
    {
        $movements = DB::table('stock_movements as m')
            ->join('product_variants as v', 'v.id', '=', 'm.product_variant_id')
            ->where('m.organization_id', $this->context->organizationId())
            ->when($request->query('location_id'), fn ($q, $v) => $q->where('m.location_id', $v))
            ->when($request->query('variant_id'), fn ($q, $v) => $q->where('m.product_variant_id', $v))
            ->orderByDesc('m.created_at')
            ->limit(min((int) $request->query('limit', 100), 500))
            ->select(['m.id', 'm.type', 'm.quantity', 'm.reason', 'm.created_at',
                      'm.reference_type', 'm.reference_id', 'v.sku'])
            ->get();

        return ApiResponse::ok($movements->map(fn ($m): array => [
            'id' => $m->id,
            'sku' => $m->sku,
            'type' => $m->type,
            'quantity' => (string) $m->quantity,
            'reason' => $m->reason,
            'reference' => $m->reference_type === null ? null
                : ['type' => $m->reference_type, 'id' => $m->reference_id],
            'created_at' => $m->created_at,
        ])->all());
    }

    /** Les écarts ouverts : ce que le gérant doit regarder aujourd'hui. */
    public function discrepancies(Request $request): JsonResponse
    {
        $rows = DB::table('stock_discrepancies as d')
            ->join('product_variants as v', 'v.id', '=', 'd.product_variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('d.organization_id', $this->context->organizationId())
            ->when(! $request->boolean('include_resolved'), fn ($q) => $q->whereNull('d.resolved_at'))
            ->orderByDesc('d.created_at')
            ->limit(200)
            ->select(['d.id', 'd.origin', 'd.expected_quantity', 'd.actual_quantity',
                      'd.created_at', 'd.resolved_at', 'v.sku', 'p.name'])
            ->get();

        return ApiResponse::ok($rows->map(fn ($d): array => [
            'id' => $d->id,
            'sku' => $d->sku,
            'name' => $d->name,
            'origin' => $d->origin,
            'expected' => (string) $d->expected_quantity,
            'actual' => (string) $d->actual_quantity,
            'created_at' => $d->created_at,
            'resolved_at' => $d->resolved_at,
        ])->all());
    }
}
