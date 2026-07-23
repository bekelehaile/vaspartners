<?php

namespace App\Models;

use App\Enums\CompanyChangeStatus;
use App\Enums\CompanyChangeType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompanyChangeRequest extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'public_id',
        'customer_id',
        'company_id',
        'type',
        'status',
        'customer_note',
        'admin_note',
        'proposal_disk',
        'proposal_path',
        'proposal_original_name',
        'proposal_size_bytes',
        'letter_disk',
        'letter_path',
        'letter_original_name',
        'letter_size_bytes',
        'reviewed_by_user_id',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => CompanyChangeType::class,
            'status' => CompanyChangeStatus::class,
            'reviewed_at' => 'datetime',
            'proposal_size_bytes' => 'integer',
            'letter_size_bytes' => 'integer',
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function hasProposal(): bool
    {
        return filled($this->proposal_path);
    }

    public function hasLetter(): bool
    {
        return filled($this->letter_path);
    }
}
