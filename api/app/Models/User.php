<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidKey;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\Access\Authorizable;

/**
 * @property string $id
 * @property ?string $email
 * @property ?string $phone
 * @property string $status
 */
final class User extends Model implements AuthenticatableContract
{
    use Authorizable, HasUuidKey;

    protected $table = 'users';

    protected $guarded = ['id'];

    protected $hidden = ['password_hash', 'totp_secret'];

    protected function casts(): array
    {
        return [
            'totp_confirmed_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(PersonalAccessToken::class);
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->totp_secret !== null && $this->totp_confirmed_at !== null;
    }

    // --- Authenticatable -----------------------------------------------------

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): string
    {
        return $this->id;
    }

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): ?string
    {
        return null;
    }
}
