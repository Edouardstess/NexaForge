<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Support\Http\ApiResponse;
use App\Support\Tenancy\OrgContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Les rapports qu'un commerçant regarde le soir.
 *
 * Deux choses gouvernent chaque requête ici :
 *
 *  — la journée est celle du FUSEAU DE L'ORGANISATION, pas UTC. Un commerce
 *    de Port-au-Prince clôture à minuit heure locale, et une journée calculée
 *    en UTC coupe sa soirée en deux.
 *  — le filtre porte sur taken_at, l'heure de la caisse, jamais created_at :
 *    des ventes hors-ligne synchronisées le lendemain appartiennent à la
 *    journée où elles ont été encaissées.                              [D-09]
 */
final class ReportController
{
    public function __construct(private readonly OrgContext $context) {}

    public function daily(Request $request): JsonResponse
    {
        $input = $request->validate([
            'location_id' => ['nullable', 'uuid'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $timezone = $this->context->timezone();
        $day = Carbon::parse($input['date'] ?? 'today', $timezone)->startOfDay();
        [$from, $to] = [$day->copy()->utc(), $day->copy()->endOfDay()->utc()];

        $locationId = $input['location_id'] ?? null;
        $currency = $this->context->baseCurrency();

        $sales = $this->scopedOrders($from, $to, $locationId)
            ->selectRaw('count(*) as orders,
                         coalesce(sum(o.total_minor),0)    as gross,
                         coalesce(sum(o.subtotal_minor),0) as net,
                         coalesce(sum(o.tax_minor),0)      as tax,
                         coalesce(sum(o.discount_minor),0) as discount,
                         coalesce(sum(o.refunded_minor),0) as refunded')
            ->first();

        // Le coût vient des lignes, au coût figé à la vente : c'est ce qui
        // rend la marge vraie même si le prix d'achat a changé depuis.
        $cost = (int) $this->scopedOrders($from, $to, $locationId)
            ->join('order_items as i', 'i.order_id', '=', 'o.id')
            ->selectRaw('coalesce(sum(i.unit_cost_minor * (i.quantity - i.refunded_quantity)),0) as cost')
            ->value('cost');

        $tenders = $this->scopedOrders($from, $to, $locationId)
            ->join('payments as p', 'p.order_id', '=', 'o.id')
            ->groupBy('p.method', 'p.currency')
            ->selectRaw('p.method, p.currency, count(*) as count,
                         sum(p.amount_minor) as amount, sum(p.base_amount_minor) as base_amount')
            ->get();

        $byHour = $this->scopedOrders($from, $to, $locationId)
            ->selectRaw("to_char(o.taken_at at time zone ?, 'HH24') as hour,
                         count(*) as orders, coalesce(sum(o.total_minor),0) as gross", [$timezone])
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();

        $grossNet = (int) $sales->gross - (int) $sales->refunded;
        $margin = $grossNet - $cost;

        return ApiResponse::ok([
            'date' => $day->toDateString(),
            'timezone' => $timezone,
            'currency' => $currency,
            'location_id' => $locationId,
            'orders' => (int) $sales->orders,
            'gross_minor' => (int) $sales->gross,
            'refunded_minor' => (int) $sales->refunded,
            'net_minor' => $grossNet,
            'subtotal_minor' => (int) $sales->net,
            'tax_minor' => (int) $sales->tax,
            'discount_minor' => (int) $sales->discount,
            'cost_minor' => $cost,
            'margin_minor' => $margin,
            // En points de base, comme les taux de taxe : pas de flottant qui
            // se promène dans un rapport.
            'margin_bp' => $grossNet > 0 ? intdiv($margin * 10000, $grossNet) : 0,
            'average_basket_minor' => (int) $sales->orders > 0
                ? intdiv($grossNet, (int) $sales->orders)
                : 0,
            'tenders' => $tenders->map(fn ($t): array => [
                'method' => $t->method,
                'currency' => $t->currency,
                'count' => (int) $t->count,
                'amount_minor' => (int) $t->amount,
                'base_amount_minor' => (int) $t->base_amount,
            ])->all(),
            'by_hour' => $byHour->map(fn ($h): array => [
                'hour' => $h->hour,
                'orders' => (int) $h->orders,
                'gross_minor' => (int) $h->gross,
            ])->all(),
        ]);
    }

    public function topProducts(Request $request): JsonResponse
    {
        $input = $request->validate([
            'location_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $timezone = $this->context->timezone();
        $from = Carbon::parse($input['from'] ?? '-30 days', $timezone)->startOfDay()->utc();
        $to = Carbon::parse($input['to'] ?? 'today', $timezone)->endOfDay()->utc();

        $rows = $this->scopedOrders($from, $to, $input['location_id'] ?? null)
            ->join('order_items as i', 'i.order_id', '=', 'o.id')
            ->join('product_variants as v', 'v.id', '=', 'i.product_variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->groupBy('v.id', 'v.sku', 'p.name', 'v.name')
            // Les quantités et montants sont NETS des retours : un article
            // vendu puis rendu n'est pas un best-seller.
            ->selectRaw('v.id as variant_id, v.sku, p.name as product, v.name as variant,
                         sum(i.quantity - i.refunded_quantity) as quantity,
                         sum(i.line_total_minor
                             - round(i.line_total_minor * i.refunded_quantity / i.quantity)) as revenue,
                         sum(coalesce(i.unit_cost_minor,0) * (i.quantity - i.refunded_quantity)) as cost')
            ->havingRaw('sum(i.quantity - i.refunded_quantity) > 0')
            // Trier par l'expression, jamais par la position dans le SELECT :
            // ajouter une colonne devant décalerait silencieusement le tri.
            ->orderByRaw('sum(i.quantity - i.refunded_quantity) desc')
            ->orderByRaw('sum(i.line_total_minor
                              - round(i.line_total_minor * i.refunded_quantity / i.quantity)) desc')
            ->limit($input['limit'] ?? 20)
            ->get();

        return ApiResponse::ok($rows->map(fn ($r): array => [
            'variant_id' => $r->variant_id,
            'sku' => $r->sku,
            'name' => $r->product.' — '.$r->variant,
            'quantity' => (string) $r->quantity,
            'revenue_minor' => (int) $r->revenue,
            'cost_minor' => (int) $r->cost,
            'margin_minor' => (int) $r->revenue - (int) $r->cost,
        ])->all(), [
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'currency' => $this->context->baseCurrency(),
        ]);
    }

    /** Ce que vaut le stock au coût d'achat : le capital immobilisé en rayon. */
    public function stockValue(Request $request): JsonResponse
    {
        $input = $request->validate(['location_id' => ['required', 'uuid']]);

        if (! $this->context->canAccessLocation($input['location_id'])) {
            return ApiResponse::error('NOT_FOUND', 'Point de vente introuvable.', 404);
        }

        $rows = DB::table('stock_levels as s')
            ->join('product_variants as v', 'v.id', '=', 's.product_variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin(DB::raw('(
                SELECT DISTINCT ON (product_variant_id, location_id)
                       product_variant_id, location_id, unit_cost_minor
                  FROM stock_movements
                 WHERE type = \'PURCHASE\' AND unit_cost_minor IS NOT NULL
                 ORDER BY product_variant_id, location_id, created_at DESC
            ) as c'), function ($join): void {
                $join->on('c.product_variant_id', '=', 's.product_variant_id')
                    ->on('c.location_id', '=', 's.location_id');
            })
            ->where('s.organization_id', $this->context->organizationId())
            ->where('s.location_id', $input['location_id'])
            ->selectRaw('v.sku, p.name, s.quantity,
                         coalesce(c.unit_cost_minor,0) as unit_cost,
                         round(s.quantity * coalesce(c.unit_cost_minor,0)) as value')
            ->orderByDesc('value')
            ->get();

        return ApiResponse::ok([
            'total_value_minor' => (int) $rows->sum('value'),
            'currency' => $this->context->baseCurrency(),
            'negative_lines' => $rows->filter(fn ($r) => (float) $r->quantity < 0)->count(),
            'items' => $rows->map(fn ($r): array => [
                'sku' => $r->sku,
                'name' => $r->name,
                'quantity' => (string) $r->quantity,
                'unit_cost_minor' => (int) $r->unit_cost,
                'value_minor' => (int) $r->value,
            ])->all(),
        ]);
    }

    /**
     * Les commandes d'une journée, filtrées sur l'heure de la caisse et
     * limitées à la portée du membre.
     */
    private function scopedOrders(Carbon $from, Carbon $to, ?string $locationId): \Illuminate\Database\Query\Builder
    {
        $query = DB::table('orders as o')
            ->where('o.organization_id', $this->context->organizationId())
            ->whereIn('o.status', ['COMPLETED', 'PARTIALLY_REFUNDED', 'REFUNDED'])
            ->whereBetween('o.taken_at', [$from, $to]);

        if ($locationId !== null) {
            $query->where('o.location_id', $locationId);
        } elseif (! $this->context->hasFullLocationAccess()) {
            $query->whereIn('o.location_id', $this->context->locationIds());
        }

        return $query;
    }
}
