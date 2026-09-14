<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;

/**
 * Restriction à la portée opérationnelle du membership.                [D-03]
 *
 * Ne s'applique PAS globalement : un propriétaire lit toutes ses locations,
 * un rapport consolidé aussi. C'est un filtre explicite, appliqué par les
 * requêtes qui répondent à « ce que cet utilisateur a le droit de voir ».
 */
trait ScopedToLocations
{
    public function scopeVisibleToCurrentUser(Builder $query, string $column = 'location_id'): Builder
    {
        $context = app(OrgContext::class);

        if (! $context->isBound() || $context->hasFullLocationAccess()) {
            return $query;
        }

        return $query->whereIn($query->qualifyColumn($column), $context->locationIds());
    }
}
