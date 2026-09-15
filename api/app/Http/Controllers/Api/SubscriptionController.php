<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Billing\SubscriptionService;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\OrgContext;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SubscriptionController
{
    public function __construct(
        private readonly OrgContext $context,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /** L'abonnement en cours, ou null. Lisible par tout membre. */
    public function current(): JsonResponse
    {
        $subscription = $this->subscriptions->current();

        return ApiResponse::ok(
            $subscription === null ? null : $this->payload($subscription),
        );
    }

    /**
     * Le catalogue des formules. Les prix sont ceux en vigueur : une grille
     * modifiée hier ne doit pas réapparaître dans l'écran d'un client.
     */
    public function plans(): JsonResponse
    {
        $plans = Plan::query()
            ->where('active', true)
            ->with(['prices' => fn ($q) => $q->inForce()])
            ->orderBy('code')
            ->get();

        return ApiResponse::ok($plans->map(fn (Plan $plan): array => [
            'id' => $plan->id,
            'code' => $plan->code,
            'name' => $plan->name,
            'max_locations' => $plan->maxLocations(),
            'max_registers' => $plan->maxRegisters(),
            'prices' => $plan->prices->map(fn (PlanPrice $price): array => [
                'id' => $price->id,
                'currency' => $price->currency,
                'interval' => $price->interval,
                'amount_minor' => $price->amount_minor,
                'per_location' => $price->per_location,
                'min_locations' => $price->min_locations,
            ])->all(),
        ])->all());
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'plan_price_id' => ['required', 'uuid'],
            'trial_days' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);

        $price = PlanPrice::query()->inForce()->find($input['plan_price_id']);

        if ($price === null) {
            return ApiResponse::error('NOT_FOUND', 'Cette formule n\'est plus proposée.', 404);
        }

        try {
            $subscription = $this->subscriptions->subscribe(
                $price,
                isset($input['trial_days']) ? (int) $input['trial_days'] : null,
            );
        } catch (DomainException $e) {
            return ApiResponse::error('SUBSCRIPTION_REFUSED', $e->getMessage(), 422);
        }

        return ApiResponse::created($this->payload($subscription));
    }

    public function changePlan(Request $request): JsonResponse
    {
        $input = $request->validate(['plan_price_id' => ['required', 'uuid']]);

        $price = PlanPrice::query()->inForce()->find($input['plan_price_id']);

        if ($price === null) {
            return ApiResponse::error('NOT_FOUND', 'Cette formule n\'est plus proposée.', 404);
        }

        try {
            return ApiResponse::ok($this->payload($this->subscriptions->changePlan($price)));
        } catch (DomainException $e) {
            return ApiResponse::error('SUBSCRIPTION_REFUSED', $e->getMessage(), 422);
        }
    }

    /**
     * Recompte les sites et facture l'écart au prorata. Exposé parce que
     * l'ouverture d'un point de vente doit pouvoir déclencher l'ajustement
     * sans attendre le renouvellement.
     */
    public function sync(): JsonResponse
    {
        $subscription = $this->subscriptions->syncQuantity();

        if ($subscription === null) {
            return ApiResponse::error('NO_SUBSCRIPTION', 'Aucun abonnement actif.', 422);
        }

        return ApiResponse::ok($this->payload($subscription));
    }

    public function cancel(Request $request): JsonResponse
    {
        $input = $request->validate(['immediately' => ['boolean']]);

        try {
            return ApiResponse::ok($this->payload(
                $this->subscriptions->cancel($input['immediately'] ?? false),
            ));
        } catch (DomainException $e) {
            return ApiResponse::error('SUBSCRIPTION_REFUSED', $e->getMessage(), 422);
        }
    }

    public function invoices(Request $request): JsonResponse
    {
        $invoices = Invoice::query()
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->query('page_size', 25), 100));

        return ApiResponse::paginated($invoices, fn (Invoice $i): array => $this->invoiceSummary($i));
    }

    public function showInvoice(string $id): JsonResponse
    {
        // Le scope global filtre sur l'organisation : la facture d'un autre
        // commerçant est introuvable, pas interdite.                    [D-03]
        $invoice = Invoice::query()->with('lines')->findOrFail($id);

        return ApiResponse::ok($this->invoiceSummary($invoice) + [
            'lines' => $invoice->lines->map(fn ($line): array => [
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_minor' => $line->unit_minor,
                'total_minor' => $line->total_minor,
            ])->all(),
        ]);
    }

    private function payload(Subscription $subscription): array
    {
        $price = $subscription->price;

        return [
            'id' => $subscription->id,
            'status' => $subscription->status,
            'quantity' => $subscription->quantity,
            'plan' => [
                'code' => $price->plan->code,
                'name' => $price->plan->name,
            ],
            'price' => [
                'id' => $price->id,
                'currency' => $price->currency,
                'interval' => $price->interval,
                'unit_minor' => $price->unitAmount()->minor,
                'per_location' => $price->per_location,
            ],
            'period_amount_minor' => $subscription->periodAmount()->minor,
            'current_period_start' => $subscription->current_period_start->toIso8601String(),
            'current_period_end' => $subscription->current_period_end->toIso8601String(),
            'grace_until' => $subscription->grace_until?->toIso8601String(),
            'cancel_at' => $subscription->cancel_at?->toIso8601String(),
        ];
    }

    private function invoiceSummary(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'status' => $invoice->status,
            'currency' => $invoice->currency,
            'subtotal_minor' => $invoice->subtotal_minor,
            'tax_minor' => $invoice->tax_minor,
            'total_minor' => $invoice->total_minor,
            'paid_minor' => $invoice->paid_minor,
            'due_at' => $invoice->due_at?->toIso8601String(),
            'created_at' => $invoice->created_at->toIso8601String(),
        ];
    }
}
