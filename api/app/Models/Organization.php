<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Le client. Frontière d'isolation ET entité facturée.                 [D-03]
 *
 * @property string $id
 * @property string $base_currency
 * @property string $timezone
 */
final class Organization extends Model
{
    use HasUuidKey;

    protected $guarded = ['id'];

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function activeLocationCount(): int
    {
        return $this->locations()->where('active', true)->count();
    }
}
