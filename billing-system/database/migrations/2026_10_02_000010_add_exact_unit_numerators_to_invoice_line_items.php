<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_line_items', function (Blueprint $table) {
            $table->unsignedBigInteger('units_included_numerator')->after('units_included');
            $table->unsignedBigInteger('overage_units_numerator')->after('overage_units');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_line_items', function (Blueprint $table) {
            $table->dropColumn(['units_included_numerator', 'overage_units_numerator']);
        });
    }
};
