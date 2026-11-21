<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)->withTimestamps();
    }

    /**
     * Return the Role this user holds in the given company, or null if no role
     * is assigned. Loads the role via the company_user pivot.
     */
    public function roleInCompany(Company $company): ?Role
    {
        $pivot = $this->companies()
            ->withPivot('role_id', 'is_owner')
            ->whereKey($company->getKey())
            ->first();

        if ($pivot === null) {
            return null;
        }

        $roleId = $pivot->pivot->role_id;

        if ($roleId === null) {
            return null;
        }

        return Role::with('permissions')->find($roleId);
    }

    /**
     * Check whether the user has the given permission key in the given company.
     * Owners (is_owner = true in the pivot) have all permissions.
     */
    public function hasCompanyPermission(Company $company, string $permission): bool
    {
        $pivot = $this->companies()
            ->withPivot('role_id', 'is_owner')
            ->whereKey($company->getKey())
            ->first();

        if ($pivot === null) {
            return false;
        }

        if ((bool) $pivot->pivot->is_owner) {
            return true;
        }

        $roleId = $pivot->pivot->role_id;
        if ($roleId === null) {
            return false;
        }

        $role = Role::with('permissions')->find($roleId);

        return $role !== null && $role->hasPermission($permission);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function uploadedAttachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'uploaded_by');
    }
}
