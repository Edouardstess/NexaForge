<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une offre du catalogue d'abonnement.                                 [D-12]
 *
 * Un plan n'appartient à AUCUNE organisation : c'est une donnée de référence,
 * commune à tous les clients. Il ne porte donc pas `organization_id` et n'est
 * pas filtré par le scope d'isolation — l'y soumettre rendrait le catalogue
 * invisible à tout le monde.
 *
 * Les limites du plan vivent dans `features` : ce sont des règles commerciales
 * qui changent sans migration, pas des colonnes.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property array<string, mixed> $features
 */
final class Plan extends Model
{
    use HasUuidKey;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'active' => 'boolean',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class);
    }

    /** null = illimité. Le plan Multi n'a pas de plafond de sites. */
    public function maxLocations(): ?int
    {
        $max = $this->features['max_locations'] ?? null;

        return $max === null ? null : (int) $max;
    }

    /** null = illimité. Starter : une caisse. Boutique : trois. */
    public function maxRegisters(): ?int
    {
        $max = $this->features['max_registers'] ?? null;

        return $max === null ? null : (int) $max;
    }
}
