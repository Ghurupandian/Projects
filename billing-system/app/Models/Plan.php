<?php

namespace App\Models;

use App\Observers\PlanObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy([PlanObserver::class])]
class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'name',
        'billing_cycle',
        'base_price_paise',
        'included_units',
        'overage_rate_paise',
        'is_active',
    ];

    protected $casts = [
        'base_price_paise' => 'integer',
        'included_units' => 'integer',
        'overage_rate_paise' => 'integer',
        'is_active' => 'boolean',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
