<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Le lien utilisateur ↔ organisation : porteur du rôle ET de la portée.
 *
 * @property string $id
 * @property string $user_id
 * @property string $organization_id
 * @property int $permissions_version
 */
final class Membership extends Model
{
    use HasUuidKey;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['permissions_version' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'membership_locations');
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }

    /**
     * Les locations accessibles. Tableau VIDE = toutes celles de l'organisation.
     *
     * @return list<string>
     */
    public function locationIds(): array
    {
        return array_values(DB::table('membership_locations')
            ->where('membership_id', $this->id)
            ->pluck('location_id')
            ->all());
    }

    /**
     * Les permissions effectives : celles du rôle, plus les GRANT explicites,
     * moins les REVOKE explicites.
     *
     * Le cache est indexé par permissions_version, donc une révocation prend
     * effet à la requête suivante — pas après l'expiration d'un TTL.
     *
     * @return list<string>
     */
    public function effectivePermissions(): array
    {
        return Cache::remember(
            "membership:{$this->id}:permissions:v{$this->permissions_version}",
            now()->addHour(),
            function (): array {
                $fromRole = DB::table('role_permissions')
                    ->where('role_id', $this->role_id)
                    ->pluck('permission_code');

                $overrides = DB::table('membership_permission_overrides')
                    ->where('membership_id', $this->id)
                    ->get();

                $granted = $fromRole
                    ->merge($overrides->where('effect', 'GRANT')->pluck('permission_code'))
                    ->diff($overrides->where('effect', 'REVOKE')->pluck('permission_code'))
                    ->unique()
                    ->sort()
                    ->values();

                return $granted->all();
            }
        );
    }

    /** Invalide le cache de permissions en faisant avancer la version. */
    public function bumpPermissionsVersion(): void
    {
        $this->increment('permissions_version');
    }
}
