<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Money;
use App\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne de facture.
 *
 * Elle ne porte pas `organization_id` : elle n'existe que par sa facture, qui
 * est elle-même isolée. Ajouter la colonne créerait un deuxième endroit où
 * l'isolation peut diverger.                                           [D-03]
 *
 * `total_minor` PEUT être négatif — c'est ainsi qu'un crédit de prorata
 * s'impute sur la facture suivante. C'est la facture, elle, qui ne peut pas
 * l'être.
 *
 * @property string $id
 * @property string $invoice_id
 * @property string $description
 * @property int $unit_minor
 * @property int $total_minor
 */
final class InvoiceLine extends Model
{
    use HasUuidKey;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'unit_minor' => 'integer',
            'total_minor' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function total(string $currency): Money
    {
        return Money::of($this->total_minor, $currency);
    }
}
