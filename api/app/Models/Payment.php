<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Money;
use App\Models\Concerns\HasUuidKey;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un encaissement. Porte SA devise et le taux appliqué, gelé.          [D-04]
 *
 * Une commande accepte plusieurs paiements de devises différentes :
 * 1 000 HTG en espèces + 5 USD + le reste en MonCash est le cas normal.
 */
final class Payment extends Model
{
    use BelongsToOrganization, HasUuidKey;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'base_amount_minor' => 'integer',
            'tendered_minor' => 'integer',
            'change_minor' => 'integer',
            'fx_rate' => 'string',
            'received_at' => 'immutable_datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Le montant réellement reçu, dans la devise reçue. */
    public function amount(): Money
    {
        return Money::of($this->amount_minor, $this->currency);
    }

    /** Sa contre-valeur dans la devise de comptabilisation de la commande. */
    public function baseAmount(): Money
    {
        return Money::of($this->base_amount_minor, $this->order->currency);
    }
}
