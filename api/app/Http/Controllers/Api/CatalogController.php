<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Catalog\PriceResolver;
use App\Domain\Shared\Money;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\OrgContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CatalogController
{
    public function __construct(
        private readonly OrgContext $context,
        private readonly PriceResolver $prices,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $products = Product::query()
            ->with(['variants', 'taxRate', 'category'])
            ->when($request->query('q'), fn ($q, $v) => $q->where('name', 'ilike', '%'.$v.'%'))
            ->when($request->query('category_id'), fn ($q, $v) => $q->where('category_id', $v))
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('active', true))
            ->orderBy('name')
            ->paginate(min((int) $request->query('page_size', 50), 200));

        $locationId = $request->query('location_id');

        return ApiResponse::paginated($products, fn (Product $p): array => $this->payload($p, $locationId));
    }

    /**
     * Le catalogue complet d'un point de vente, prix et stock inclus.
     *
     * C'est ce que la caisse télécharge avant de passer hors-ligne : une
     * seule requête, tout ce qu'il faut pour vendre sans réseau.        [D-09]
     */
    public function snapshot(Request $request): JsonResponse
    {
        $input = $request->validate([
            'location_id' => ['required', 'uuid'],
        ]);

        if (! $this->context->canAccessLocation($input['location_id'])) {
            return ApiResponse::error('NOT_FOUND', 'Point de vente introuvable.', 404);
        }

        $currency = $this->context->baseCurrency();

        $rows = DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('tax_rates as t', 't.id', '=', 'p.tax_rate_id')
            ->leftJoin('units as u', 'u.id', '=', 'v.unit_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('stock_levels as s', function ($join) use ($input): void {
                $join->on('s.product_variant_id', '=', DB::raw('coalesce(v.base_variant_id, v.id)'))
                    ->where('s.location_id', '=', $input['location_id']);
            })
            ->where('v.organization_id', $this->context->organizationId())
            ->where('v.active', true)
            ->where('p.active', true)
            ->orderBy('p.name')
            ->select([
                'v.id', 'v.sku', 'v.barcode', 'v.name as variant_name', 'v.pack_size',
                'v.base_variant_id', 'p.id as product_id', 'p.name as product_name',
                'p.track_stock', 'c.name as category', 'u.code as unit',
                DB::raw('coalesce(t.rate_bp, 0) as rate_bp'),
                DB::raw('coalesce(t.inclusive, true) as tax_inclusive'),
                DB::raw('coalesce(s.quantity, 0) as stock'),
            ])
            ->get();

        $items = $rows->map(function ($row) use ($input, $currency): array {
            try {
                $price = $this->prices->resolve($row->id, $input['location_id'], $currency);
                $priceMinor = $price->minor;
            } catch (\RuntimeException) {
                // Un article sans prix n'est pas vendable, mais il ne doit pas
                // faire échouer le téléchargement de tout le catalogue.
                $priceMinor = null;
            }

            return [
                'variant_id' => $row->id,
                'product_id' => $row->product_id,
                'name' => $row->product_name,
                'variant_name' => $row->variant_name,
                'sku' => $row->sku,
                'barcode' => $row->barcode,
                'unit' => $row->unit,
                'category' => $row->category,
                'pack_size' => $row->pack_size,
                'base_variant_id' => $row->base_variant_id,
                'price_minor' => $priceMinor,
                'currency' => $currency,
                'tax_rate_bp' => (int) $row->rate_bp,
                'tax_inclusive' => (bool) $row->tax_inclusive,
                'track_stock' => (bool) $row->track_stock,
                'stock' => (string) $row->stock,
            ];
        })->all();

        $rates = DB::table('exchange_rates')
            ->where('organization_id', $this->context->organizationId())
            ->where('base_currency', $currency)
            ->where('effective_from', '<=', now())
            ->orderByDesc('effective_from')
            ->get()
            ->unique('quote_currency')
            ->map(fn ($r): array => [
                'quote_currency' => $r->quote_currency,
                'rate' => (string) $r->rate,
                'effective_from' => $r->effective_from,
            ])->values()->all();

        return ApiResponse::ok([
            'location_id' => $input['location_id'],
            'base_currency' => $currency,
            'exchange_rates' => $rates,
            'items' => $items,
        ], ['count' => count($items), 'generated_at' => now()->toIso8601String()]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:1000'],
            'category_id' => ['nullable', 'uuid'],
            'tax_rate_id' => ['nullable', 'uuid'],
            'track_stock' => ['boolean'],
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.sku' => ['required', 'string', 'max:60'],
            'variants.*.name' => ['required', 'string', 'max:120'],
            'variants.*.unit_id' => ['required', 'uuid'],
            'variants.*.barcode' => ['nullable', 'string', 'max:60'],
            'variants.*.pack_size' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,4})?$/'],
            'variants.*.base_variant_sku' => ['nullable', 'string', 'max:60'],
            'variants.*.price_minor' => ['nullable', 'integer', 'min:0'],
        ]);

        $product = DB::transaction(function () use ($input): Product {
            $product = Product::query()->create([
                'name' => $input['name'],
                'description' => $input['description'] ?? null,
                'category_id' => $input['category_id'] ?? null,
                'tax_rate_id' => $input['tax_rate_id'] ?? null,
                'track_stock' => $input['track_stock'] ?? true,
            ]);

            $bySku = [];

            // Deux passes : les conditionnements référencent la variante de
            // base par son SKU, qui peut n'exister qu'à la fin de la liste.
            foreach ($input['variants'] as $v) {
                $bySku[$v['sku']] = ProductVariant::query()->create([
                    'product_id' => $product->id,
                    'unit_id' => $v['unit_id'],
                    'sku' => $v['sku'],
                    'name' => $v['name'],
                    'barcode' => $v['barcode'] ?? null,
                    'pack_size' => $v['pack_size'] ?? '1',
                ]);
            }

            foreach ($input['variants'] as $v) {
                $variant = $bySku[$v['sku']];

                if (isset($v['base_variant_sku'])) {
                    $base = $bySku[$v['base_variant_sku']]
                        ?? ProductVariant::query()->where('sku', $v['base_variant_sku'])->first();

                    if ($base !== null) {
                        $variant->forceFill(['base_variant_id' => $base->id])->save();
                    }
                }

                if (isset($v['price_minor'])) {
                    $this->prices->setPrice(
                        $variant->id,
                        Money::of($v['price_minor'], $this->context->baseCurrency()),
                    );
                }
            }

            return $product;
        });

        return ApiResponse::created($this->payload($product->load(['variants', 'taxRate', 'category']), null));
    }

    public function setPrice(Request $request, string $variantId): JsonResponse
    {
        $input = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'location_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date'],
        ]);

        $variant = ProductVariant::query()->findOrFail($variantId);

        $this->prices->setPrice(
            $variant->id,
            Money::of($input['amount_minor'], $input['currency'] ?? $this->context->baseCurrency()),
            $input['location_id'] ?? null,
            isset($input['from']) ? new \DateTimeImmutable($input['from']) : null,
        );

        return ApiResponse::created([
            'variant_id' => $variant->id,
            'amount_minor' => $input['amount_minor'],
            'currency' => $input['currency'] ?? $this->context->baseCurrency(),
        ]);
    }

    private function payload(Product $product, ?string $locationId): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'category' => $product->category?->name,
            'track_stock' => $product->track_stock,
            'active' => $product->active,
            'tax_rate_bp' => $product->taxRate?->rate_bp ?? 0,
            'tax_inclusive' => $product->taxRate?->inclusive ?? true,
            'variants' => $product->variants->map(function (ProductVariant $v) use ($locationId): array {
                $price = null;

                if ($locationId !== null) {
                    try {
                        $price = $this->prices->resolve($v->id, $locationId)->minor;
                    } catch (\RuntimeException) {
                        $price = null;
                    }
                }

                return [
                    'id' => $v->id,
                    'sku' => $v->sku,
                    'name' => $v->name,
                    'barcode' => $v->barcode,
                    'pack_size' => $v->pack_size,
                    'base_variant_id' => $v->base_variant_id,
                    'price_minor' => $price,
                ];
            })->all(),
        ];
    }
}
