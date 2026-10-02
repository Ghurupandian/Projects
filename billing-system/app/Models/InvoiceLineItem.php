<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLineItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'plan_id',
        'description',
        'segment_start',
        'segment_end',
        'days_in_segment',
        'days_in_cycle',
        'units_used',
        'units_included',
        'units_included_numerator',
        'overage_units',
        'overage_units_numerator',
        'overage_rate_paise',
        'base_amount_paise',
        'overage_amount_paise',
        'subtotal_paise',
    ];

    protected $casts = [
        'segment_start' => 'date',
        'segment_end' => 'date',
        'days_in_segment' => 'integer',
        'days_in_cycle' => 'integer',
        'units_used' => 'integer',
        'units_included' => 'integer',
        'units_included_numerator' => 'integer',
        'overage_units' => 'integer',
        'overage_units_numerator' => 'integer',
        'overage_rate_paise' => 'integer',
        'base_amount_paise' => 'integer',
        'overage_amount_paise' => 'integer',
        'subtotal_paise' => 'integer',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
