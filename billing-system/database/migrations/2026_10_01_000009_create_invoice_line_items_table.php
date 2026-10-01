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
        Schema::create('invoice_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->string('description');
            $table->date('segment_start');
            $table->date('segment_end');
            $table->unsignedSmallInteger('days_in_segment');
            $table->unsignedSmallInteger('days_in_cycle');
            $table->unsignedBigInteger('units_used');
            $table->unsignedBigInteger('units_included');
            $table->unsignedBigInteger('overage_units');
            $table->unsignedBigInteger('overage_rate_paise');
            $table->unsignedBigInteger('base_amount_paise');
            $table->unsignedBigInteger('overage_amount_paise');
            $table->unsignedBigInteger('subtotal_paise');
            $table->timestamps();

            $table->index('invoice_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_line_items');
    }
};
