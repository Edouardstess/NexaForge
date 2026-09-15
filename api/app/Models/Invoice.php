<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Money;
use App\Models\Concerns\HasUuidKey;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une facture d'abonnement.
 *
 * La base impose `total_minor = subtotal_minor + tax_minor` et
 * `0 <= paid_minor <= total_minor`. Ces CHECK disent deux choses qu'il faut
 * lire : une facture ne peut pas être NÉGATIVE, et on ne peut pas encaisser
 * plus que ce qui est dû. Un avoir n'est donc pas une facture à l'envers —
 * c'est un crédit porté sur la facture suivante.        [voir CreditNote dans
 * SubscriptionService]
 *
 * @property string $id
 * @property string $organization_id
 * @property string $number
 * @property string $currency
 * @property int $total_minor
 * @property int $paid_minor
 * @property string $status
 */
final class Invoice extends Model
{
    use BelongsToOrganization, HasUuidKey;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'subtotal_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'paid_minor' => 'integer',
            'due_at' => 'immutable_datetime',
        ];
    }

    public function lines(): HasMany
    {
        // Les lignes n'ont pas d'horodatage : leur identifiant est un UUID v7,
        // donc l'ordre des clés EST l'ordre d'écriture.
        return $this->hasMany(InvoiceLine::class)->orderBy('id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function total(): Money
    {
        return Money::of($this->total_minor, $this->currency);
    }

    public function balance(): Money
    {
        return Money::of($this->total_minor - $this->paid_minor, $this->currency);
    }

    public function isSettled(): bool
    {
        return in_array($this->status, ['PAID', 'VOID'], true);
    }
}
