<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Receipt\ReceiptBuilder;
use App\Domain\Sales\CheckoutService;
use App\Domain\Sales\RefundService;
use App\Models\CashierSession;
use App\Models\Order;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\OrgContext;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les ventes.
 *
 * POST /orders exige une Idempotency-Key : une caisse qui perd le réseau
 * réessaie, et le client ne doit pas être débité deux fois.            [D-11]
 */
final class OrderController
{
    public function __construct(
        private readonly OrgContext $context,
        private readonly CheckoutService $checkout,
        private readonly RefundService $refunds,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'cashier_session_id' => ['required', 'uuid'],
            'customer_id' => ['nullable', 'uuid'],
            'client_order_id' => ['nullable', 'string', 'max:80'],
            // Une horloge de caisse qui avance ne doit pas pousser des
            // ventes dans le rapport de demain. Un peu de jeu pour la
            // dérive normale, pas plus.
            'taken_at' => ['nullable', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
            'origin' => ['nullable', 'in:ONLINE,OFFLINE'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.variant_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'string', 'regex:/^\d+(\.\d{1,4})?$/'],
            'lines.*.unit_price_minor' => ['nullable', 'integer', 'min:0'],
            'lines.*.discount_minor' => ['nullable', 'integer', 'min:0'],

            'tenders' => ['required', 'array', 'min:1'],
            'tenders.*.method' => ['required', 'in:CASH,MONCASH,NATCASH,CARD,CREDIT'],
            'tenders.*.currency' => ['required', 'string', 'size:3'],
            'tenders.*.amount_minor' => ['required', 'integer', 'min:1'],
            'tenders.*.fx_rate' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,8})?$/'],
            'tenders.*.tendered_minor' => ['nullable', 'integer', 'min:0'],
            'tenders.*.reference' => ['nullable', 'string', 'max:120'],
        ]);

        $session = CashierSession::query()->find($input['cashier_session_id']);

        if ($session === null || ! $this->context->canAccessLocation($session->location_id)) {
            return ApiResponse::error('NOT_FOUND', 'Session de caisse introuvable.', 404);
        }

        // Une vente hors-ligne arrivée après la clôture n'est pas refusée :
        // elle a été encaissée avant, la clôture est juste passée devant. Le
        // service la rattache et fait repasser le Z en amendé.          [D-09]
        if (! $session->isOpen() && ($input['origin'] ?? 'ONLINE') !== 'OFFLINE') {
            return ApiResponse::error(
                'SESSION_NOT_OPEN',
                'Cette session est clôturée. Ouvrez une nouvelle caisse.',
                409,
            );
        }

        try {
            $order = $this->checkout->checkout(
                session: $session,
                lines: $input['lines'],
                tenders: $input['tenders'],
                customerId: $input['customer_id'] ?? null,
                clientOrderId: $input['client_order_id'] ?? null,
                takenAt: isset($input['taken_at']) ? new \DateTimeImmutable($input['taken_at']) : null,
                origin: $input['origin'] ?? 'ONLINE',
            );
        } catch (UniqueConstraintViolationException) {
            // La caisse a rejoué une vente déjà enregistrée sous ce
            // client_order_id. Ce n'est pas une erreur : on lui rend la vente
            // qui existe, pour qu'elle marque sa ligne SYNCED et avance. [D-09]
            $existing = Order::query()
                ->where('client_order_id', $input['client_order_id'])
                ->with(['items', 'payments'])
                ->first();

            if ($existing !== null) {
                return ApiResponse::ok($this->payload($existing), ['replayed' => true]);
            }

            throw new \RuntimeException('Conflit d\'unicité sans commande correspondante.');
        } catch (DomainException $e) {
            return ApiResponse::error('CHECKOUT_REFUSED', $e->getMessage(), 422);
        }

        return ApiResponse::created($this->payload($order));
    }

    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->visibleToCurrentUser()
            ->when($request->query('location_id'), fn ($q, $v) => $q->where('location_id', $v))
            ->when($request->query('session_id'), fn ($q, $v) => $q->where('cashier_session_id', $v))
            ->when($request->query('from'), fn ($q, $v) => $q->where('taken_at', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->where('taken_at', '<=', $v))
            // Sur taken_at, jamais created_at : une caisse restée hors-ligne
            // six heures ne doit pas voir ses ventes du matin rangées au soir.
            //
            // Le numéro de commande départage : deux ventes encaissées dans la
            // même seconde n'ont sinon pas d'ordre stable, et en pagination
            // une ligne apparaît deux fois pendant qu'une autre disparaît.
            ->orderByDesc('taken_at')
            ->orderByDesc('order_number')
            ->paginate(min((int) $request->query('page_size', 25), 100));

        return ApiResponse::paginated($orders, fn (Order $o): array => $this->summary($o));
    }

    public function show(string $id): JsonResponse
    {
        $order = Order::query()->visibleToCurrentUser()->with(['items', 'payments', 'refunds'])->findOrFail($id);

        return ApiResponse::ok($this->payload($order));
    }

    /**
     * Le ticket. Texte brut par défaut, octets ESC/POS pour une imprimante
     * thermique. Construit côté serveur : deux caisses de versions
     * différentes ne doivent pas imprimer deux mises en page.
     */
    public function receipt(Request $request, string $id): Response
    {
        $input = $request->validate([
            'format' => ['nullable', 'in:text,escpos'],
            'width' => ['nullable', 'integer', 'in:32,42'],
        ]);

        $order = Order::query()
            ->visibleToCurrentUser()
            ->with(['items', 'payments', 'location'])
            ->findOrFail($id);

        $membership = $this->context->membership();

        $builder = new ReceiptBuilder(
            // Une valeur de query string est une chaîne : la règle
            // « integer » la valide mais ne la convertit pas.
            width: (int) ($input['width'] ?? 42),
            timezone: $this->context->timezone(),
        );

        $context = [
            'organization' => $membership->organization->name,
            'location' => $order->location->name,
            'address' => $order->location->address,
            'phone' => $order->location->phone,
            'cashier' => $membership->user->first_name ?? null,
        ];

        if (($input['format'] ?? 'text') === 'escpos') {
            // application/octet-stream : ces octets partent vers une
            // imprimante, pas vers un moteur de rendu.
            return response($builder->escpos($order, $context), 200, [
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="'.$order->order_number.'.bin"',
            ]);
        }

        return response($builder->text($order, $context), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    public function refund(Request $request, string $id): JsonResponse
    {
        $input = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.order_item_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'string', 'regex:/^\d+(\.\d{1,4})?$/'],
            'reason' => ['required', 'string', 'min:3', 'max:200'],
            'restock' => ['boolean'],
            'method' => ['nullable', 'in:CASH,MONCASH,NATCASH,CARD,CREDIT'],
        ]);

        $order = Order::query()->visibleToCurrentUser()->findOrFail($id);

        try {
            $refund = $this->refunds->refund(
                order: $order,
                lines: $input['lines'],
                reason: $input['reason'],
                restock: $input['restock'] ?? true,
                method: $input['method'] ?? 'CASH',
            );
        } catch (DomainException $e) {
            return ApiResponse::error('REFUND_REFUSED', $e->getMessage(), 422);
        }

        return ApiResponse::created($refund);
    }

    private function summary(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'currency' => $order->currency,
            'total_minor' => $order->total_minor,
            'refunded_minor' => $order->refunded_minor,
            'origin' => $order->origin,
            'taken_at' => $order->taken_at->toIso8601String(),
        ];
    }

    private function payload(Order $order): array
    {
        return $this->summary($order) + [
            'location_id' => $order->location_id,
            'cashier_session_id' => $order->cashier_session_id,
            'customer_id' => $order->customer_id,
            'subtotal_minor' => $order->subtotal_minor,
            'discount_minor' => $order->discount_minor,
            'tax_minor' => $order->tax_minor,
            'client_order_id' => $order->client_order_id,
            'synced_at' => $order->synced_at?->toIso8601String(),
            'items' => $order->items->map(fn ($i): array => [
                'id' => $i->id,
                'product_variant_id' => $i->product_variant_id,
                'description' => $i->description,
                'quantity' => $i->quantity,
                'unit_price_minor' => $i->unit_price_minor,
                'discount_minor' => $i->discount_minor,
                'tax_rate_bp' => $i->tax_rate_bp,
                'tax_minor' => $i->tax_minor,
                'line_total_minor' => $i->line_total_minor,
            ])->all(),
            'payments' => $order->payments->map(fn ($p): array => [
                'id' => $p->id,
                'method' => $p->method,
                'currency' => $p->currency,
                'amount_minor' => $p->amount_minor,
                'fx_rate' => $p->fx_rate,
                'base_amount_minor' => $p->base_amount_minor,
                'change_minor' => $p->change_minor,
                'received_at' => $p->received_at->toIso8601String(),
            ])->all(),
        ];
    }
}
