<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    protected $fillable = [
        'key',
        'module',
        'action',
        'name_en',
        'name_ar',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions');
    }

    /**
     * Return all permission entries from the catalog service.
     *
     * @return Collection<int, self>
     */
    public static function catalog(): Collection
    {
        return self::all();
    }
}
