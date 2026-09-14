<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use RuntimeException;

/**
 * Isolation par organisation, appliquée dans le modèle de données et pas
 * seulement dans les contrôleurs.                                      [D-03]
 *
 * - toute lecture est filtrée sur l'organisation active ;
 * - toute écriture est estampillée automatiquement ;
 * - toute écriture nommant une AUTRE organisation est refusée, même si le code
 *   appelant l'a demandée explicitement.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new class implements Scope
        {
            public function apply(Builder $builder, Model $model): void
            {
                $context = app(OrgContext::class);

                if (! $context->isBound()) {
                    return;
                }

                $builder->where(
                    $model->qualifyColumn('organization_id'),
                    $context->organizationId()
                );
            }
        });

        static::creating(function (Model $model): void {
            $context = app(OrgContext::class);

            if (! $context->isBound()) {
                return;
            }

            $model->organization_id ??= $context->organizationId();

            self::assertSameOrganization($model, $context);
        });

        static::updating(function (Model $model): void {
            $context = app(OrgContext::class);

            if ($context->isBound()) {
                self::assertSameOrganization($model, $context);
            }
        });
    }

    private static function assertSameOrganization(Model $model, OrgContext $context): void
    {
        if ($model->organization_id !== $context->organizationId()) {
            throw new RuntimeException(sprintf(
                'Écriture refusée : %s viserait l\'organisation %s alors que le contexte actif est %s.',
                $model::class,
                $model->organization_id ?? 'null',
                $context->organizationId(),
            ));
        }
    }

    /** Retire le filtre d'organisation. Réservé aux tâches plateforme. */
    public static function acrossAllOrganizations(): Builder
    {
        return static::query()->withoutGlobalScopes();
    }
}
