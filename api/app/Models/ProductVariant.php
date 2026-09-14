<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidKey;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une déclinaison vendable. pack_size + base_variant_id résolvent le cas
 * central du commerce haïtien : acheter la caisse, vendre l'unité.      [D-06]
 */
final class ProductVariant extends Model
{
    use BelongsToOrganization, HasUuidKey;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['pack_size' => 'string', 'active' => 'boolean', 'version' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function baseVariant(): BelongsTo
    {
        return $this->belongsTo(self::class, 'base_variant_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** La variante sur laquelle le stock est réellement tenu. */
    public function stockVariantId(): string
    {
        return $this->base_variant_id ?? $this->id;
    }

    /** Une caisse de 24 vendue, ce sont 24 unités déduites du stock. */
    public function toStockQuantity(string $quantity): string
    {
        if ($this->base_variant_id === null) {
            return $quantity;
        }

        $scale = 4;
        $product = \App\Domain\Shared\Decimal::toScaledInt($quantity, $scale)
            * \App\Domain\Shared\Decimal::toScaledInt($this->pack_size, $scale);

        return \App\Domain\Shared\Decimal::fromScaledInt(
            intdiv($product, 10 ** $scale),
            $scale,
        );
    }
}
