<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Shared\Money;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Subscription;
use App\Support\Tenancy\OrgContext;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Les factures d'abonnement.
 *
 * Deux invariants, tenus ici et vérifiés en base :
 *
 * 1. **La somme des lignes est exactement le sous-total.** Une facture dont
 *    les lignes ne s'additionnent pas au total est une facture qu'un client
 *    conteste, et il a raison. Le contrôle est une exception, pas un log.
 * 2. **Le numéro est unique par organisation**, tiré d'un compteur verrouillé
 *    exactement comme les numéros de commande : deux factures émises dans la
 *    même milliseconde ne peuvent pas porter le même numéro.
 */
final class InvoiceService
{
    /** Le compteur de `sequence_counters` dédié aux factures. */
    private const SCOPE = 'invoice';

    public function __construct(private readonly OrgContext $context) {}

    /**
     * Émet une facture et ses lignes, dans une seule transaction.
     *
     * @param  list<array{description: string, quantity?: string, unit?: Money, total: Money}>  $lines
     */
    public function issue(
        string $currency,
        array $lines,
        ?Subscription $subscription = null,
        string $status = 'OPEN',
        ?DateTimeInterface $dueAt = null,
    ): Invoice {
        if ($lines === []) {
            throw new LogicException('Une facture sans ligne n\'a rien à facturer.');
        }

        $subtotal = $this->sum($currency, $lines);

        // La base refuse `paid_minor <= total_minor` quand le total est
        // négatif : un avoir n'est pas une facture à l'envers. Si ce code
        // essaie d'en émettre un, c'est un bug d'appelant, pas un cas métier.
        if ($subtotal->isNegative()) {
            throw new LogicException(
                'Une facture négative est un état impossible : un crédit se porte '.
                'sur la facture suivante, il ne s\'émet pas seul.'
            );
        }

        return DB::transaction(function () use ($currency, $lines, $subscription, $status, $dueAt, $subtotal): Invoice {
            $invoice = Invoice::query()->create([
                'subscription_id' => $subscription?->id,
                'number' => $this->nextNumber(),
                'currency' => $currency,
                'subtotal_minor' => $subtotal->minor,
                // La TCA ne s'applique pas à l'abonnement au MVP : le service
                // est facturé hors champ. Le jour où elle s'appliquera, elle se
                // décomposera à la ligne comme sur un ticket.            [D-07]
                'tax_minor' => 0,
                'total_minor' => $subtotal->minor,
                'paid_minor' => 0,
                'status' => $status,
                'due_at' => $dueAt,
            ]);

            $this->writeLines($invoice, $lines);

            return $invoice;
        });
    }

    /**
     * Réécrit les lignes d'une facture et recalcule ses totaux.
     *
     * Réservé aux brouillons : une facture ouverte est un engagement, on n'en
     * change pas les lignes sous le nez du client.
     *
     * @param  list<array{description: string, quantity?: string, unit?: Money, total: Money}>  $lines
     */
    public function replaceLines(Invoice $invoice, array $lines): Invoice
    {
        if ($invoice->status !== 'DRAFT') {
            throw new LogicException('Seule une facture au brouillon peut voir ses lignes réécrites.');
        }

        $subtotal = $this->sum($invoice->currency, $lines);

        if ($subtotal->isNegative()) {
            throw new LogicException('Le brouillon ne peut pas descendre sous zéro.');
        }

        return DB::transaction(function () use ($invoice, $lines, $subtotal): Invoice {
            $invoice->lines()->delete();
            $this->writeLines($invoice, $lines);

            $invoice->forceFill([
                'subtotal_minor' => $subtotal->minor,
                'tax_minor' => 0,
                'total_minor' => $subtotal->minor,
            ])->save();

            return $invoice->refresh();
        });
    }

    /** Fait passer un brouillon à l'état exigible. */
    public function open(Invoice $invoice, ?DateTimeInterface $dueAt = null): Invoice
    {
        $invoice->forceFill([
            'status' => 'OPEN',
            'due_at' => $dueAt ?? $invoice->due_at,
        ])->save();

        return $invoice;
    }

    /**
     * Encaisse tout ou partie d'une facture.
     *
     * Le paiement est plafonné au solde : la base interdit `paid > total`, et
     * un trop-perçu silencieux vaut mieux refusé que caché.
     */
    public function pay(Invoice $invoice, Money $amount): Invoice
    {
        if ($amount->currency !== $invoice->currency) {
            throw new LogicException(
                "Règlement en {$amount->currency} sur une facture en {$invoice->currency} : ".
                'une conversion explicite est requise.'
            );
        }

        if ($amount->greaterThan($invoice->balance())) {
            throw new LogicException('Le règlement dépasse le solde de la facture.');
        }

        $paid = $invoice->paid_minor + $amount->minor;

        $invoice->forceFill([
            'paid_minor' => $paid,
            'status' => $paid >= $invoice->total_minor ? 'PAID' : $invoice->status,
        ])->save();

        return $invoice;
    }

    /**
     * Le numéro suivant, par organisation, via un compteur verrouillé.
     *
     * Même mécanique que les numéros de commande : l'UPDATE ... RETURNING
     * verrouille la ligne pour la durée de la transaction, donc deux émissions
     * concurrentes s'attendent au lieu de lire la même valeur.
     */
    private function nextNumber(): string
    {
        $organizationId = $this->context->organizationId();

        DB::statement(
            'INSERT INTO sequence_counters (organization_id, scope, value) VALUES (?, ?, 0)
             ON CONFLICT (organization_id, scope) DO NOTHING',
            [$organizationId, self::SCOPE],
        );

        $next = DB::selectOne(
            'UPDATE sequence_counters SET value = value + 1
              WHERE organization_id = ? AND scope = ?
              RETURNING value',
            [$organizationId, self::SCOPE],
        );

        return sprintf('FAC-%06d', $next->value);
    }

    /** @param list<array{description: string, quantity?: string, unit?: Money, total: Money}> $lines */
    private function sum(string $currency, array $lines): Money
    {
        // Money refuse d'additionner deux devises : une ligne en USD sur une
        // facture en HTG lève ici plutôt que de produire un total plausible.
        return array_reduce(
            $lines,
            fn (Money $carry, array $line): Money => $carry->plus($line['total']),
            Money::zero($currency),
        );
    }

    /** @param list<array{description: string, quantity?: string, unit?: Money, total: Money}> $lines */
    private function writeLines(Invoice $invoice, array $lines): void
    {
        foreach ($lines as $line) {
            InvoiceLine::query()->create([
                'invoice_id' => $invoice->id,
                'description' => $line['description'],
                'quantity' => $line['quantity'] ?? '1',
                'unit_minor' => ($line['unit'] ?? $line['total'])->minor,
                'total_minor' => $line['total']->minor,
            ]);
        }
    }
}
