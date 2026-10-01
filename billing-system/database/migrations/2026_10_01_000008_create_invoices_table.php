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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->string('invoice_number', 50)->unique();
            $table->date('cycle_start');
            $table->date('cycle_end');
            $table->unsignedBigInteger('base_amount_paise');
            $table->unsignedBigInteger('overage_amount_paise');
            $table->unsignedBigInteger('total_amount_paise');
            $table->char('currency', 3)->default('INR');
            $table->string('status', 50)->default('issued');
            $table->timestamps();

            $table->unique(['subscription_id', 'cycle_start', 'cycle_end']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
