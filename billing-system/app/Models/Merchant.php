<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Merchant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'api_key_hash',
    ];

    /**
     * Compute SHA-256 hash for API key lookup.
     */
    public static function hashApiKey(string $plainApiKey): string
    {
        return hash('sha256', $plainApiKey);
    }

    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }
}
