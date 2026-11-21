<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = [
        'company_id',
        'name_en',
        'name_ar',
        'slug',
        'description',
        'is_system',
        'is_protected',
    ];

    protected function casts(): array
    {
        return [
            'is_system'    => 'boolean',
            'is_protected' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    /**
     * Check whether this role has the given permission key.
     */
    public function hasPermission(string $key): bool
    {
        return $this->permissions->contains('key', $key);
    }
}
