<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Feedback extends Model
{
    use HasUlids;

    protected $table = 'feedback';

    protected $fillable = [
        'public_id',
        'contact_id',
        'company_id',
        'year',
        'quarter',
        'rating',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'quarter' => 'integer',
            'rating' => 'integer',
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

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public static function currentYear(): int
    {
        return (int) now()->year;
    }

    public static function currentQuarter(): int
    {
        return (int) ceil(now()->month / 3);
    }

    public function quarterLabel(): string
    {
        return 'Q'.$this->quarter.' '.$this->year;
    }
}
