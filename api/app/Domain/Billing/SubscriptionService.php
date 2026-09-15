<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Shared\Money;
use App\Models\Location;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Support\Tenancy\OrgContext;
use DateTimeInterface;
use DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * L'abonnement d'une organisation.                                     [D-12]
 *
 * Un seul abonnement vivant par organisation, dont la quantité est le nombre
 * de points de vente actifs. Le commerçant reçoit donc une facture, un cycle
 * et un prix par site — il comprend ce qu'il paie, et il n'y a qu'un objet à
 * gérer.
 *
 * L'unicité n'est pas vérifiée ici : un index partiel la garantit en base.
 * Un check-then-insert laisserait passer deux requêtes simultanées.
 */
final class SubscriptionService
{
    /** Délai avant suspension d'un abonnement impayé. */
    public const GRACE_DAYS = 7;

    public function __construct(
        private readonly OrgContext $context,
        private readonly InvoiceService $invoices,
    ) {}

    public function current(): ?Subscription
    {
        return Subscription::query()->live()->with('price.plan')->first();
    }

    /**
     * Abonne l'organisation. La quantité est comptée, pas fournie : le client
     * ne choisit pas combien de boutiques il déclare.
     */
    public function subscribe(PlanPrice $price, ?int $trialDays = null): Subscription
    {
        if ($this->current() !== null) {
            throw new DomainException('Cette organisation a déjà un abonnement actif.');
        }

        $quantity = $this->billableQuantity($price);
        $start = CarbonImmutable::now();

        return DB::transaction(function () use ($price, $quantity, $start, $trialDays): Subscription {
            $trialing = $trialDays !== null && $trialDays > 0;

            $subscription = Subscription::query()->create([
                'plan_price_id' => $price->id,
                'quantity' => $quantity,
                'status' => $trialing ? 'TRIALING' : 'ACTIVE',
                'current_period_start' => $start,
                'current_period_end' => $trialing
                    ? $start->addDays($trialDays)
                    : $price->advance($start),
            ]);

            // Un essai ne se facture pas : la première facture part au
            // renouvellement, quand le commerçant a vu le produit marcher.
            if (! $trialing) {
                $this->invoices->issue(
                    currency: $price->currency,
                    lines: [[
                        'description' => $this->periodLabel($subscription),
                        'quantity' => (string) $quantity,
                        'unit' => $price->unitAmount(),
                        'total' => $price->amountFor($quantity),
                    ]],
                    subscription: $subscription,
                    dueAt: $start->addDays(self::GRACE_DAYS),
                );
            }

            return $subscription->load('price.plan');
        });
    }

    /**
     * Recompte les sites et facture la différence au prorata du temps restant.
     *
     * **Asymétrique, et c'est voulu.** Une hausse est facturée au prorata : un
     * commerçant qui ouvre le 25 ne paie pas un mois entier. Une baisse n'est
     * pas remboursée : la quantité tombe, et c'est la facture SUIVANTE qui est
     * plus petite.
     *
     * Deux raisons. La base ne sait pas représenter un avoir — le CHECK
     * `paid_minor <= total_minor` interdit une facture négative — et un avoir
     * qui se reporte demanderait un état de plus à tenir juste. Et la règle
     * telle quelle s'explique en une phrase à un commerçant, ce qui compte
     * plus qu'une exactitude qu'il ne saurait pas vérifier.
     */
    public function syncQuantity(?DateTimeInterface $at = null): ?Subscription
    {
        $subscription = $this->current();

        if ($subscription === null) {
            return null;
        }

        $price = $subscription->price;
        $quantity = $this->billableQuantity($price);

        if ($quantity === $subscription->quantity) {
            return $subscription;
        }

        $at = CarbonImmutable::instance(
            $at === null ? CarbonImmutable::now() : CarbonImmutable::instance($at)
        );

        $before = $price->amountFor($subscription->quantity);
        $after = $price->amountFor($quantity);
        $delta = $after->minus($before);

        // Une baisse ne se facture pas : elle prend effet au renouvellement.
        $prorated = $delta->isPositive()
            ? $this->prorate($delta, $subscription, $at)
            : Money::zero($price->currency);

        return DB::transaction(function () use ($subscription, $quantity, $prorated, $price, $at): Subscription {
            $subscription->forceFill(['quantity' => $quantity])->save();

            // Rien à facturer sur une baisse, ni quand le prorata tombe à
            // zéro au dernier jour de la période.
            if ($prorated->isPositive()) {
                $this->invoices->issue(
                    currency: $price->currency,
                    lines: [[
                        'description' => sprintf(
                            'Ajustement — %d point%s de vente au %s',
                            $quantity,
                            $quantity > 1 ? 's' : '',
                            $at->timezone($this->context->timezone())->format('d/m/Y'),
                        ),
                        'quantity' => '1',
                        'unit' => $prorated,
                        'total' => $prorated,
                    ]],
                    subscription: $subscription,
                    dueAt: $at->addDays(self::GRACE_DAYS),
                );
            }

            return $subscription->fresh(['price.plan']);
        });
    }

    /** Ouvre la période suivante et émet sa facture. */
    public function renew(?DateTimeInterface $at = null): Subscription
    {
        $subscription = $this->current()
            ?? throw new DomainException('Aucun abonnement à renouveler.');

        $at = $at === null ? CarbonImmutable::now() : CarbonImmutable::instance($at);
        $price = $subscription->price;
        $quantity = $this->billableQuantity($price);

        return DB::transaction(function () use ($subscription, $price, $quantity, $at): Subscription {
            $start = $subscription->current_period_end;

            $subscription->forceFill([
                'status' => 'ACTIVE',
                'quantity' => $quantity,
                'current_period_start' => $start,
                'current_period_end' => $price->advance($start),
                'grace_until' => null,
            ])->save();

            $renewed = $subscription->fresh(['price.plan']);

            $this->invoices->issue(
                currency: $price->currency,
                lines: [[
                    'description' => $this->periodLabel($renewed),
                    'quantity' => (string) $quantity,
                    'unit' => $price->unitAmount(),
                    'total' => $price->amountFor($quantity),
                ]],
                subscription: $renewed,
                dueAt: $at->addDays(self::GRACE_DAYS),
            );

            return $renewed;
        });
    }

    /**
     * Passe en impayé, puis en suspendu une fois la grâce écoulée.
     *
     * La caisse ne s'arrête pas le jour où une facture est en retard : un
     * commerçant dont le virement traîne doit pouvoir continuer à vendre,
     * sinon on lui coûte sa journée pour notre problème de recouvrement.
     */
    public function markPastDue(?DateTimeInterface $at = null): Subscription
    {
        $subscription = $this->current()
            ?? throw new DomainException('Aucun abonnement à marquer impayé.');

        $at = $at === null ? CarbonImmutable::now() : CarbonImmutable::instance($at);

        $subscription->forceFill([
            'status' => 'PAST_DUE',
            'grace_until' => $at->addDays(self::GRACE_DAYS),
        ])->save();

        return $subscription->fresh(['price.plan']);
    }

    public function suspendIfGraceElapsed(?DateTimeInterface $at = null): ?Subscription
    {
        $subscription = $this->current();
        $at = $at === null ? CarbonImmutable::now() : CarbonImmutable::instance($at);

        if ($subscription === null
            || $subscription->status !== 'PAST_DUE'
            || $subscription->grace_until === null
            || $subscription->grace_until->greaterThan($at)) {
            return $subscription;
        }

        $subscription->forceFill(['status' => 'SUSPENDED'])->save();

        return $subscription->fresh(['price.plan']);
    }

    /**
     * Résilie. Par défaut à la fin de la période déjà payée : le commerçant a
     * réglé son mois, il le garde.
     */
    public function cancel(bool $immediately = false, ?DateTimeInterface $at = null): Subscription
    {
        $subscription = $this->current()
            ?? throw new DomainException('Aucun abonnement à résilier.');

        $at = $at === null ? CarbonImmutable::now() : CarbonImmutable::instance($at);

        $subscription->forceFill($immediately
            ? ['status' => 'CANCELLED', 'cancel_at' => $at]
            : ['cancel_at' => $subscription->current_period_end])->save();

        return $subscription->fresh(['price.plan']);
    }

    /** Change de formule : on résilie et on réabonne dans la même transaction. */
    public function changePlan(PlanPrice $price): Subscription
    {
        $subscription = $this->current()
            ?? throw new DomainException('Aucun abonnement à modifier.');

        if ($subscription->plan_price_id === $price->id) {
            return $subscription;
        }

        return DB::transaction(function () use ($subscription, $price): Subscription {
            $subscription->forceFill(['status' => 'CANCELLED', 'cancel_at' => CarbonImmutable::now()])->save();

            return $this->subscribe($price);
        });
    }

    /**
     * Le nombre de sites facturables : ceux qui sont actifs, jamais moins que
     * le minimum de la formule.
     */
    public function billableQuantity(PlanPrice $price): int
    {
        if (! $price->per_location) {
            return 1;
        }

        $active = Location::query()->where('active', true)->count();

        return max($active, $price->min_locations);
    }

    /**
     * La part d'un montant qui correspond au temps restant.
     *
     * Arithmétique entière, arrondi à mi-chemin supérieur, magnitude et signe
     * traités séparément : un crédit et un débit du même nombre de jours
     * doivent donner le même montant au centime près, sinon un aller-retour
     * d'ouverture et de fermeture laisse un résidu.
     */
    private function prorate(Money $amount, Subscription $subscription, CarbonImmutable $at): Money
    {
        $start = $subscription->current_period_start;
        $end = $subscription->current_period_end;

        // diffInSeconds rend un float en Carbon 3 : intdiv le refuse, et un
        // cast implicite sur une durée serait une perte silencieuse.
        $total = (int) $start->diffInSeconds($end);

        if ($total <= 0) {
            return Money::zero($amount->currency);
        }

        $remaining = max(0, min($total, (int) $at->diffInSeconds($end, absolute: false)));

        $magnitude = intdiv(abs($amount->minor) * $remaining * 2 + $total, $total * 2);

        return Money::of($amount->minor < 0 ? -$magnitude : $magnitude, $amount->currency);
    }

    private function periodLabel(Subscription $subscription): string
    {
        $timezone = $this->context->timezone();

        return sprintf(
            '%s — %s au %s',
            $subscription->price->plan->name,
            $subscription->current_period_start->timezone($timezone)->format('d/m/Y'),
            $subscription->current_period_end->timezone($timezone)->format('d/m/Y'),
        );
    }
}
