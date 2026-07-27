<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    use SoftDeletes;

    public const KEY_GROUP_1 = 'group_1';

    public const KEY_GROUP_2 = 'group_2';

    protected $fillable = ['key', 'name', 'slug', 'description', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * Stable operational groups (renameable display names).
     */
    public function scopeOperationalGroups(Builder $query): Builder
    {
        return $query
            ->whereIn('key', [self::KEY_GROUP_1, self::KEY_GROUP_2])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class)->withTimestamps();
    }

    /** Legacy single-FK relation still used by older rows / reports. */
    public function primaryServices(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
