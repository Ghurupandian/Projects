<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('name');
            $table->string('billing_cycle', 50)->default('monthly');
            $table->unsignedBigInteger('base_price_paise');
            $table->unsignedBigInteger('included_units');
            $table->unsignedBigInteger('overage_rate_paise');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['merchant_id', 'is_active']);
            $table->unique(['merchant_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
