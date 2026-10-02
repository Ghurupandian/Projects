<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyUsage extends Model
{
    protected $fillable = [
        'merchant_id',
        'customer_id',
        'usage_date',
        'total_units',
    ];

    protected $casts = [
        'usage_date' => 'date',
        'total_units' => 'integer',
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
