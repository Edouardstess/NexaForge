<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Clé primaire UUID v7 : ordonnée dans le temps, donc les insertions restent
 * localisées dans l'index au lieu de le fragmenter comme le fait un v4. [D-05]
 */
trait HasUuidKey
{
    public function getKeyType(): string
    {
        return 'string';
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    public static function bootHasUuidKey(): void
    {
        static::creating(function ($model): void {
            // La base a déjà un DEFAULT, mais l'affecter ici rend l'identifiant
            // disponible avant l'insertion (relations, événements, réponses).
            $model->{$model->getKeyName()} ??= (string) Str::uuid7();
        });
    }
}
