<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Membership;
use RuntimeException;

/**
 * Le contexte de l'organisation active.                                [D-03]
 *
 * Source unique de vérité pour « qui agit, dans quelle organisation, sur
 * quelles locations ». Jamais renseigné depuis le corps de la requête : il est
 * dérivé du jeton porteur et du membership en base.
 *
 * locationIds vide signifie « toutes les locations de l'organisation ».
 */
final class OrgContext
{
    private ?Membership $membership = null;

    /** @var list<string>|null */
    private ?array $permissions = null;

    public function bind(Membership $membership): void
    {
        $this->membership = $membership;
        $this->permissions = null;
    }

    public function forget(): void
    {
        $this->membership = null;
        $this->permissions = null;
    }

    public function isBound(): bool
    {
        return $this->membership !== null;
    }

    public function membership(): Membership
    {
        return $this->membership ?? throw new RuntimeException(
            'Aucune organisation active. Ce code ne doit pas tourner hors du contexte d\'une requête authentifiée.'
        );
    }

    public function organizationId(): string
    {
        return $this->membership()->organization_id;
    }

    public function userId(): string
    {
        return $this->membership()->user_id;
    }

    public function baseCurrency(): string
    {
        return $this->membership()->organization->base_currency;
    }

    public function timezone(): string
    {
        return $this->membership()->organization->timezone;
    }

    /**
     * Les locations accessibles. Tableau VIDE = toutes.
     *
     * @return list<string>
     */
    public function locationIds(): array
    {
        return $this->membership()->locationIds();
    }

    public function hasFullLocationAccess(): bool
    {
        return $this->locationIds() === [];
    }

    public function canAccessLocation(string $locationId): bool
    {
        return $this->hasFullLocationAccess()
            || in_array($locationId, $this->locationIds(), true);
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return $this->permissions ??= $this->membership()->effectivePermissions();
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}
