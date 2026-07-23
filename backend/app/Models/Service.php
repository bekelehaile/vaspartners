<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use SoftDeletes;

    protected $fillable = ['category_id', 'name', 'slug', 'description', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function requisitions(): BelongsToMany
    {
        return $this->belongsToMany(Requisition::class);
    }

    public function documentMatrix(): HasMany
    {
        return $this->hasMany(ServiceRequisitionDocument::class);
    }

    public function finalApprovers(): HasMany
    {
        return $this->hasMany(ServiceFinalApprover::class);
    }
}
