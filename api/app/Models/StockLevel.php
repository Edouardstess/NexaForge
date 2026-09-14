<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * L'état courant du stock. Clé composite, donc pas de clé primaire Eloquent :
 * ce modèle sert la lecture ; les écritures passent par StockService, qui
 * verrouille la ligne avant de la modifier.                            [D-08]
 */
final class StockLevel extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'stock_levels';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'string', 'reserved' => 'string'];
    }
}
