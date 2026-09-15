<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Money;
use App\Models\Concerns\HasUuidKey;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * L'abonnement d'une organisation.                                     [D-12]
 *
 * UN abonnement par Organization, dont la quantité est le nombre de Locations
 * actives. Un commerçant à trois boutiques paie un abonnement de quantité 3,
 * pas trois abonnements : une seule facture, un seul cycle, un prix par site.
 *
 * L'unicité n'est pas vérifiée ici : un index unique PARTIEL en base la tient
 * sur les statuts vivants. Un check-then-insert applicatif laisserait passer
 * deux requêtes simultanées ; l'index, lui, ne se trompe jamais.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $plan_price_id
 * @property int $quantity
 * @property string $status
 */
final class Subscription extends Model
{
    use BelongsToOrganization, HasUuidKey;

    /** Les statuts couverts par l'index unique partiel `subscriptions_one_live`. */
    public const LIVE_STATUSES = ['TRIALING', 'ACTIVE', 'PAST_DUE'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'current_period_start' => 'immutable_datetime',
            'current_period_end' => 'immutable_datetime',
            'grace_until' => 'immutable_datetime',
            'cancel_at' => 'immutable_datetime',
        ];
    }

    public function price(): BelongsTo
    {
        return $this->belongsTo(PlanPrice::class, 'plan_price_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', self::LIVE_STATUSES);
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE_STATUSES, true);
    }

    /** Le montant d'une période entière au tarif et à la quantité courants. */
    public function periodAmount(): Money
    {
        return $this->price->amountFor($this->quantity);
    }
}
