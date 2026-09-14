<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidKey;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

final class Category extends Model
{
    use BelongsToOrganization, HasUuidKey;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
