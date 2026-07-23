<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceFinalApprover extends Model
{
    protected $fillable = ['service_id', 'requisition_id', 'user_id'];

    public function service(): BelongsTo { return $this->belongsTo(Service::class); }
    public function requisition(): BelongsTo { return $this->belongsTo(Requisition::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
