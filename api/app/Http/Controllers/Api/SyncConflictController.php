<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Support\Http\ApiResponse;
use App\Support\Tenancy\OrgContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Les conflits de synchronisation : ce que le gérant doit regarder.
 *
 * Aucun de ces conflits n'a bloqué une vente — c'est le principe. Ils disent
 * ce que le serveur a accepté en sachant que ce n'était pas net, pour qu'une
 * personne tranche ensuite.                                            [D-09]
 */
final class SyncConflictController
{
    /** Ce que chaque type veut dire, en une phrase, pour l'écran du gérant. */
    private const EXPLANATIONS = [
        'STOCK_NEGATIVE' => 'Le stock est passé sous zéro : comptez le rayon.',
        'PRICE_VARIANCE' => 'La caisse a vendu à un prix différent de celui en vigueur.',
        'ARCHIVED_PRODUCT' => 'Un article retiré de la vente a quand même été vendu.',
        'EXPIRED_DISCOUNT' => 'Une remise expirée a été honorée.',
        'SESSION_CLOSED' => 'Une vente est arrivée après la clôture : le Z a été recalculé.',
    ];

    public function __construct(private readonly OrgContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $rows = DB::table('sync_conflicts as c')
            ->leftJoin('orders as o', 'o.id', '=', 'c.order_id')
            ->leftJoin('locations as l', 'l.id', '=', 'c.location_id')
            ->where('c.organization_id', $this->context->organizationId())
            ->when(! $request->boolean('include_resolved'), fn ($q) => $q->whereNull('c.resolved_at'))
            ->when($request->query('kind'), fn ($q, $v) => $q->where('c.kind', $v))
            ->when(
                ! $this->context->hasFullLocationAccess(),
                fn ($q) => $q->whereIn('c.location_id', $this->context->locationIds()),
            )
            ->orderByDesc('c.created_at')
            ->limit(min((int) $request->query('limit', 100), 500))
            ->select([
                'c.id', 'c.kind', 'c.details', 'c.created_at', 'c.resolved_at',
                'o.order_number', 'o.total_minor', 'o.taken_at', 'l.code as location_code',
            ])
            ->get();

        $open = DB::table('sync_conflicts')
            ->where('organization_id', $this->context->organizationId())
            ->whereNull('resolved_at')
            ->groupBy('kind')
            ->selectRaw('kind, count(*) as count')
            ->pluck('count', 'kind');

        return ApiResponse::ok(
            $rows->map(fn ($c): array => [
                'id' => $c->id,
                'kind' => $c->kind,
                'explanation' => self::EXPLANATIONS[$c->kind] ?? $c->kind,
                'details' => json_decode((string) $c->details, true, 512, JSON_THROW_ON_ERROR),
                'order' => $c->order_number === null ? null : [
                    'number' => $c->order_number,
                    'total_minor' => (int) $c->total_minor,
                    'taken_at' => $c->taken_at,
                ],
                'location_code' => $c->location_code,
                'created_at' => $c->created_at,
                'resolved_at' => $c->resolved_at,
            ])->all(),
            ['open_by_kind' => $open, 'open_total' => $open->sum()],
        );
    }

    public function resolve(Request $request, string $id): JsonResponse
    {
        $request->validate(['note' => ['nullable', 'string', 'max:300']]);

        $updated = DB::table('sync_conflicts')
            ->where('organization_id', $this->context->organizationId())
            ->where('id', $id)
            ->whereNull('resolved_at')
            ->update([
                'resolved_at' => now(),
                'resolved_by' => $this->context->userId(),
            ]);

        if ($updated === 0) {
            return ApiResponse::error('NOT_FOUND', 'Conflit introuvable ou déjà traité.', 404);
        }

        return ApiResponse::ok(['id' => $id, 'resolved_at' => now()->toIso8601String()]);
    }
}
