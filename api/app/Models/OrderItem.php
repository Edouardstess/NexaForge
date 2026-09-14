<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidKey;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Le prix et le coût sont FIGÉS à la vente : une vente est un fait
 * historique, pas une jointure. Changer un prix aujourd'hui ne doit pas
 * modifier la marge d'une vente d'il y a trois mois.                   [D-06]
 */
final class OrderItem extends Model
{
    use BelongsToOrganization, HasUuidKey;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'string',
            'unit_price_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_rate_bp' => 'integer',
            'tax_minor' => 'integer',
            'line_total_minor' => 'integer',
            'unit_cost_minor' => 'integer',
            'position' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
