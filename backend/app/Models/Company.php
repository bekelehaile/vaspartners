<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'public_id',
        'name',
        'tin',
        'phone',
        'email',
        'address',
        'is_active',
        'created_by_customer_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'created_by_customer_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(CompanyChangeRequest::class);
    }
}
