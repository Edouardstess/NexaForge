<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Ledger\ChartOfAccounts;
use App\Domain\Ledger\LedgerService;
use App\Domain\Shared\Money;
use App\Models\CashierSession;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\OrgContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ouverture et clôture de caisse.
 *
 * Le fonds et le comptage sont tenus PAR DEVISE : le tiroir d'un commerce
 * haïtien contient des gourdes ET des dollars, et un Z qui les mélange ne
 * vaut rien.                                                           [D-04]
 */
final class CashierSessionController
{
    public function __construct(
        private readonly OrgContext $context,
        private readonly LedgerService $ledger,
    ) {}

    public function current(Request $request): JsonResponse
    {
        $session = CashierSession::query()
            ->where('location_id', $request->query('location_id'))
            ->where('status', 'OPEN')
            ->first();

        return ApiResponse::ok($session === null ? null : $this->payload($session));
    }

    public function open(Request $request): JsonResponse
    {
        $input = $request->validate([
            'location_id' => ['required', 'uuid'],
            'register_code' => ['required', 'string', 'max:40'],
            'opening' => ['array'],
            'opening.*.currency' => ['required', 'string', 'size:3'],
            'opening.*.amount_minor' => ['required', 'integer', 'min:0'],
        ]);

        if (! $this->context->canAccessLocation($input['location_id'])) {
            return ApiResponse::error('NOT_FOUND', 'Point de vente introuvable.', 404);
        }

        $open = CashierSession::query()
            ->where('location_id', $input['location_id'])
            ->where('register_code', $input['register_code'])
            ->where('status', 'OPEN')
            ->first();

        if ($open !== null) {
            return ApiResponse::error(
                'SESSION_ALREADY_OPEN',
                'Cette caisse a déjà une session ouverte.',
                409,
                ['session_id' => $open->id],
            );
        }

        $session = DB::transaction(function () use ($input): CashierSession {
            $session = CashierSession::query()->create([
                'location_id' => $input['location_id'],
                'register_code' => $input['register_code'],
                'opened_by' => $this->context->userId(),
                'opened_at' => now(),
                'status' => 'OPEN',
            ]);

            foreach ($input['opening'] ?? [] as $float) {
                DB::table('cashier_session_totals')->insert([
                    'cashier_session_id' => $session->id,
                    'currency' => strtoupper($float['currency']),
                    'opening_minor' => $float['amount_minor'],
                    'expected_minor' => $float['amount_minor'],
                ]);
            }

            return $session;
        });

        return ApiResponse::created($this->payload($session));
    }

    /**
     * Clôture : on compare ce que le caissier a compté à ce que le ledger
     * dit qu'il devrait y avoir. L'écart est enregistré, jamais absorbé.
     */
    public function close(Request $request, string $id): JsonResponse
    {
        $input = $request->validate([
            'counted' => ['required', 'array', 'min:1'],
            'counted.*.currency' => ['required', 'string', 'size:3'],
            'counted.*.amount_minor' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $session = CashierSession::query()->findOrFail($id);

        if (! $session->isOpen()) {
            return ApiResponse::error('SESSION_NOT_OPEN', 'Cette session est déjà clôturée.', 409);
        }

        $payload = DB::transaction(function () use ($session, $input): array {
            $variances = [];

            foreach ($input['counted'] as $count) {
                $currency = strtoupper($count['currency']);
                $expected = $this->expectedCash($session, $currency);

                DB::statement(
                    'INSERT INTO cashier_session_totals
                        (cashier_session_id, currency, opening_minor, expected_minor, counted_minor)
                     VALUES (?, ?, 0, ?, ?)
                     ON CONFLICT (cashier_session_id, currency) DO UPDATE
                        SET expected_minor = EXCLUDED.expected_minor,
                            counted_minor  = EXCLUDED.counted_minor',
                    [$session->id, $currency, $expected->minor, $count['amount_minor']],
                );

                $variance = Money::of($count['amount_minor'] - $expected->minor, $currency);

                if (! $variance->isZero()) {
                    // Un écart de caisse est une écriture comptable, pas une
                    // note dans un coin : il doit apparaître dans les comptes.
                    $this->ledger->post(
                        'cashier_session',
                        $session->id,
                        [
                            ['account' => ChartOfAccounts::CASH_VARIANCE, 'amount' => $variance->negated(), 'location_id' => $session->location_id],
                            ['account' => ChartOfAccounts::CASH_DRAWER, 'amount' => $variance, 'location_id' => $session->location_id],
                        ],
                        "Écart de caisse — session {$session->register_code}",
                    );
                }

                $variances[$currency] = [
                    'expected_minor' => $expected->minor,
                    'counted_minor' => $count['amount_minor'],
                    'variance_minor' => $variance->minor,
                ];
            }

            $session->forceFill([
                'status' => 'CLOSED',
                'closed_by' => $this->context->userId(),
                'closed_at' => now(),
                'notes' => $input['notes'] ?? null,
            ])->save();

            return $variances;
        });

        // array_merge, pas « + » : l'union de tableaux garde la clé de GAUCHE,
        // donc « totals » calculé ici serait silencieusement écrasé par la
        // liste brute de payload() et l'écart disparaîtrait de la réponse.
        return ApiResponse::ok(array_merge($this->payload($session->fresh()), ['totals' => $payload]));
    }

    /** Le Z : ce que la session a fait, lu depuis les ventes et le ledger. */
    public function report(string $id): JsonResponse
    {
        $session = CashierSession::query()->findOrFail($id);

        $sales = DB::table('orders')
            ->where('cashier_session_id', $session->id)
            ->where('status', '<>', 'VOIDED')
            ->selectRaw('count(*) as count, coalesce(sum(total_minor),0) as gross,
                         coalesce(sum(tax_minor),0) as tax, coalesce(sum(discount_minor),0) as discount')
            ->first();

        $byMethod = DB::table('payments as p')
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->where('o.cashier_session_id', $session->id)
            ->groupBy('p.method', 'p.currency')
            ->selectRaw('p.method, p.currency, count(*) as count,
                         sum(p.amount_minor) as amount, sum(p.base_amount_minor) as base_amount')
            ->get();

        return ApiResponse::ok([
            'session' => $this->payload($session),
            'sales' => [
                'count' => (int) $sales->count,
                'gross_minor' => (int) $sales->gross,
                'tax_minor' => (int) $sales->tax,
                'discount_minor' => (int) $sales->discount,
                'currency' => $this->context->baseCurrency(),
            ],
            'tenders' => $byMethod->map(fn ($row): array => [
                'method' => $row->method,
                'currency' => $row->currency,
                'count' => (int) $row->count,
                'amount_minor' => (int) $row->amount,
                'base_amount_minor' => (int) $row->base_amount,
            ])->all(),
        ]);
    }

    /**
     * Ce qui devrait être dans le tiroir : le fonds d'ouverture, plus les
     * espèces encaissées, moins la monnaie rendue, MOINS les remboursements
     * payés en espèces.
     *
     * Oublier les remboursements fait apparaître un excédent fantôme chaque
     * soir chez un commerçant qui rend de l'argent — et un écart qu'on ne
     * sait pas expliquer finit par être ignoré, ce qui tue le contrôle.
     */
    private function expectedCash(CashierSession $session, string $currency): Money
    {
        $opening = (int) DB::table('cashier_session_totals')
            ->where('cashier_session_id', $session->id)
            ->where('currency', $currency)
            ->value('opening_minor');

        $received = (int) DB::table('payments as p')
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->where('o.cashier_session_id', $session->id)
            ->where('p.method', 'CASH')
            ->where('p.currency', $currency)
            ->sum('p.amount_minor');

        $isBase = $currency === $this->context->baseCurrency();

        // La monnaie et les remboursements sortent en devise de
        // comptabilisation : ils ne touchent pas le tiroir en dollars.
        $change = $isBase
            ? (int) DB::table('payments as p')
                ->join('orders as o', 'o.id', '=', 'p.order_id')
                ->where('o.cashier_session_id', $session->id)
                ->sum('p.change_minor')
            : 0;

        $refunded = $isBase
            ? (int) DB::table('refunds as r')
                ->join('orders as o', 'o.id', '=', 'r.order_id')
                ->where('o.cashier_session_id', $session->id)
                ->where('r.method', 'CASH')
                ->where('r.currency', $currency)
                ->sum('r.amount_minor')
            : 0;

        return Money::of($opening + $received - $change - $refunded, $currency);
    }

    private function payload(CashierSession $session): array
    {
        return [
            'id' => $session->id,
            'location_id' => $session->location_id,
            'register_code' => $session->register_code,
            'status' => $session->status,
            'opened_at' => $session->opened_at->toIso8601String(),
            'closed_at' => $session->closed_at?->toIso8601String(),
            'totals' => DB::table('cashier_session_totals')
                ->where('cashier_session_id', $session->id)
                ->get()
                ->map(fn ($row): array => [
                    'currency' => $row->currency,
                    'opening_minor' => (int) $row->opening_minor,
                    'expected_minor' => (int) $row->expected_minor,
                    'counted_minor' => $row->counted_minor === null ? null : (int) $row->counted_minor,
                    'variance_minor' => $row->variance_minor === null ? null : (int) $row->variance_minor,
                ])->all(),
        ];
    }
}
