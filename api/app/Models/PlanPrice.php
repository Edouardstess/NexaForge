<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Money;
use App\Models\Concerns\HasUuidKey;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Le prix d'un plan, dans UNE devise et pour UNE périodicité.          [D-12]
 *
 * C'est le modèle qui manquait complètement aux documents, et c'est lui qui
 * permet de raisonner en USD — 15, 25, 20 USD sont les chiffres du plan
 * d'affaires — tout en facturant en HTG, la devise dans laquelle le
 * commerçant encaisse réellement.                                      [D-04]
 *
 * Le prix est VERSIONNÉ par `valid_from` / `valid_to`, jamais modifié en
 * place : réviser le tarif du mois ne doit pas réécrire la facture d'il y a
 * trois mois.                                                          [D-06]
 *
 * @property string $id
 * @property string $plan_id
 * @property string $currency
 * @property string $interval
 * @property int $amount_minor
 * @property bool $per_location
 * @property int $min_locations
 */
final class PlanPrice extends Model
{
    use HasUuidKey;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'min_locations' => 'integer',
            'per_location' => 'boolean',
            'valid_from' => 'immutable_datetime',
            'valid_to' => 'immutable_datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** Les tarifs en vigueur à une date : ceux qu'on a le droit de vendre. */
    public function scopeInForce(Builder $query, ?DateTimeInterface $at = null): Builder
    {
        $at ??= now();

        return $query
            ->where('valid_from', '<=', $at)
            ->where(fn (Builder $q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $at));
    }

    public function unitAmount(): Money
    {
        return Money::of($this->amount_minor, $this->currency);
    }

    /**
     * Le montant d'une période ENTIÈRE pour cette quantité.
     *
     * Multiplication entière : un prix par site ne s'arrondit jamais, donc
     * trois sites coûtent exactement trois fois un site, au centime près.
     */
    public function amountFor(int $quantity): Money
    {
        return Money::of(
            $this->amount_minor * ($this->per_location ? max($quantity, 1) : 1),
            $this->currency,
        );
    }

    /**
     * La fin de la période commencée à cette date.
     *
     * « Sans débordement » : un abonnement pris le 31 janvier se renouvelle le
     * 28 février, pas le 3 mars. Sinon la date de facturation dérive d'un mois
     * sur l'autre et le client ne sait plus quand il paie.
     */
    public function advance(DateTimeInterface $from): CarbonImmutable
    {
        $from = CarbonImmutable::parse($from);

        return $this->interval === 'YEAR'
            ? $from->addYearsNoOverflow(1)
            : $from->addMonthsNoOverflow(1);
    }
}
