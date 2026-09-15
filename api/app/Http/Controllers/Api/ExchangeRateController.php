<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Money\ExchangeRateService;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\OrgContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Le taux du jour, saisi par le propriétaire.                          [D-04]
 *
 * Pas d'API de taux automatique : en Haïti le taux pratiqué au comptoir
 * n'est pas le taux BRH, et un commerçant veut le sien.
 */
final class ExchangeRateController
{
    public function __construct(
        private readonly OrgContext $context,
        private readonly ExchangeRateService $rates,
    ) {}

    public function index(): JsonResponse
    {
        $base = $this->context->baseCurrency();

        $current = DB::table('exchange_rates')
            ->where('organization_id', $this->context->organizationId())
            ->where('base_currency', $base)
            ->where('effective_from', '<=', now())
            ->orderByDesc('effective_from')
            ->get()
            ->unique('quote_currency')
            ->map(fn ($r): array => [
                'quote_currency' => $r->quote_currency,
                'rate' => (string) $r->rate,
                'effective_from' => $r->effective_from,
                'age_days' => $this->rates->ageInDays($r->quote_currency),
                // Au-delà d'une semaine, le taux affiché ne reflète plus le
                // marché et le commerçant perd de l'argent sans le voir.
                'stale' => ($this->rates->ageInDays($r->quote_currency) ?? 0) > 7,
            ])->values()->all();

        return ApiResponse::ok(['base_currency' => $base, 'rates' => $current]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'quote_currency' => ['required', 'string', 'size:3'],
            'rate' => ['required', 'string', 'regex:/^\d+(\.\d{1,8})?$/'],
            'effective_from' => ['nullable', 'date'],
        ]);

        if (strtoupper($input['quote_currency']) === $this->context->baseCurrency()) {
            return ApiResponse::error(
                'VALIDATION_FAILED',
                'La devise de comptabilisation ne se convertit pas en elle-même.',
                422,
            );
        }

        $this->rates->record(
            $input['quote_currency'],
            $input['rate'],
            isset($input['effective_from']) ? new \DateTimeImmutable($input['effective_from']) : null,
        );

        return ApiResponse::created([
            'base_currency' => $this->context->baseCurrency(),
            'quote_currency' => strtoupper($input['quote_currency']),
            'rate' => $input['rate'],
        ]);
    }
}
