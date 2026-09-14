<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidKey;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * rate_bp est en points de base : la TCA haïtienne à 10 % vaut 1000. [D-07]
 * Jamais un flottant — 0.1 n'existe pas exactement en binaire.
 */
final class TaxRate extends Model
{
    use BelongsToOrganization, HasUuidKey;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rate_bp' => 'integer', 'inclusive' => 'boolean', 'active' => 'boolean'];
    }
}
