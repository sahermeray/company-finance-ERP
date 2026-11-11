<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('party_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->nullableMorphs('sourceable');
            $table->uuid('transfer_group')->nullable();
            $table->string('movement_type', 30);
            $table->string('direction', 10);
            $table->decimal('quantity', 18, 4);
            $table->dateTime('occurred_at');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(
                ['company_id', 'product_id', 'warehouse_id', 'occurred_at'],
                'stock_movements_tenant_product_wh_date_idx'
            );
            $table->index(['company_id', 'occurred_at'], 'stock_movements_company_date_idx');
            $table->index('project_id');
            $table->index('party_id');
            $table->index('transfer_group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
