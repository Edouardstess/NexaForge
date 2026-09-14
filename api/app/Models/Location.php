<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidKey;
use App\Support\Tenancy\BelongsToOrganization;
use App\Support\Tenancy\ScopedToLocations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un point de vente ou un dépôt physique.                              [D-03]
 *
 * @property string $id
 * @property string $organization_id
 * @property string $display_unit
 */
final class Location extends Model
{
    use BelongsToOrganization, HasUuidKey, ScopedToLocations;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Diviseur d'affichage. HTD5 (« dollar haïtien ») vaut 5 gourdes et n'est
     * PAS une devise : c'est une unité de compte, purement visuelle. [D-04]
     */
    public function displayDivisor(): int
    {
        return $this->display_unit === 'HTD5' ? 5 : 1;
    }
}
