<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Money;
use App\Models\Concerns\HasUuidKey;
use App\Support\Tenancy\BelongsToOrganization;
use App\Support\Tenancy\ScopedToLocations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une vente.
 *
 * `taken_at` est l'horloge de la CAISSE, distincte de `created_at`. Les
 * rapports journaliers se font sur `taken_at` : sinon une caisse restée
 * hors-ligne six heures fait apparaître ses ventes du matin dans le chiffre
 * du soir.                                                             [D-09]
 */
final class Order extends Model
{
    use BelongsToOrganization, HasUuidKey, ScopedToLocations;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'refunded_minor' => 'integer',
            'version' => 'integer',
            'taken_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashierSession::class, 'cashier_session_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function total(): Money
    {
        return Money::of($this->total_minor, $this->currency);
    }

    public function refundableAmount(): Money
    {
        return Money::of($this->total_minor - $this->refunded_minor, $this->currency);
    }
}
