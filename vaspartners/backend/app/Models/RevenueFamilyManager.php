<?php

namespace App\Models;

use App\Enums\RevenueServiceFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RevenueFamilyManager extends Model
{
    protected $fillable = [
        'service_family',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'service_family' => RevenueServiceFamily::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
