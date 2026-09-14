<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidKey;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

final class Customer extends Model
{
    use BelongsToOrganization, HasUuidKey;

    protected $guarded = ['id'];
}
