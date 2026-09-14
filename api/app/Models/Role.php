<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Un rôle est un MODÈLE de permissions, pas une identité de contrôle.
 * Les policies vérifient des permissions, jamais un nom de rôle. [D-03]
 */
final class Role extends Model
{
    use HasUuidKey;

    public $timestamps = false;

    protected $guarded = ['id'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'role_permissions',
            'role_id',
            'permission_code',
            'id',
            'code',
        );
    }

    public function isSystemTemplate(): bool
    {
        return $this->organization_id === null;
    }
}
