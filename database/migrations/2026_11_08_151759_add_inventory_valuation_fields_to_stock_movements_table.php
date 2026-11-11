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
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->decimal('base_cost_amount', 24, 4)->nullable();
            $table->decimal('base_unit_cost', 24, 10)->nullable();
            $table->foreignId('cost_source_stock_movement_id')
                ->nullable()
                ->constrained('stock_movements')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cost_source_stock_movement_id');
            $table->dropColumn(['base_cost_amount', 'base_unit_cost']);
        });
    }
};
