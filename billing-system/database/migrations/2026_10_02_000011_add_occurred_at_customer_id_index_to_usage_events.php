<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_events', function (Blueprint $table) {
            $table->index(['occurred_at', 'customer_id'], 'usage_events_occurred_at_customer_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('usage_events', function (Blueprint $table) {
            $table->dropIndex('usage_events_occurred_at_customer_id_index');
        });
    }
};
