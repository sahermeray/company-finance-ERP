<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'code'], 'product_categories_company_code_unique');
            $table->index(['company_id', 'name'], 'product_categories_company_name_idx');
            $table->index('parent_id');
        });

        Schema::create('unit_of_measures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('symbol', 20)->nullable();
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'code'], 'unit_measures_company_code_unique');
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('unit_of_measure_id')->constrained()->restrictOnDelete();
            $table->string('sku')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('tracks_inventory')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'sku'], 'products_company_sku_unique');
            $table->index(['company_id', 'name'], 'products_company_name_idx');
            $table->index('product_category_id');
            $table->index('unit_of_measure_id');
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code')->nullable();
            $table->string('name');
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'code'], 'warehouses_company_code_unique');
            $table->unique(['company_id', 'name'], 'warehouses_company_name_unique');
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('party_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 30)->default('active');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'code'], 'projects_company_code_unique');
            $table->index(['company_id', 'status'], 'projects_company_status_idx');
            $table->index('party_id');
        });

        Schema::create('financial_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_account_id')->nullable()->constrained('financial_accounts')->nullOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('account_type', 30);
            $table->char('currency', 3)->default('USD');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code'], 'financial_accounts_company_code_unique');
            $table->index('parent_account_id');
            $table->index(['company_id', 'account_type'], 'financial_accounts_company_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_accounts');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('products');
        Schema::dropIfExists('unit_of_measures');
        Schema::dropIfExists('product_categories');
    }
};
