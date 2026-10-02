<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'merchant_id',
        'customer_id',
        'idempotency_key',
        'units',
        'occurred_at',
        'created_at',
    ];

    protected $casts = [
        'units' => 'integer',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
