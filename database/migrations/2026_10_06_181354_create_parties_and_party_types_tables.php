<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('party_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'code'], 'party_types_company_code_unique');
        });

        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code')->nullable();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('tax_identifier')->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'code'], 'parties_company_code_unique');
            $table->index(['company_id', 'name'], 'parties_company_name_idx');
        });

        Schema::create('party_party_type', function (Blueprint $table) {
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_type_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['party_id', 'party_type_id'], 'party_party_type_unique');
            $table->index('party_type_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_party_type');
        Schema::dropIfExists('parties');
        Schema::dropIfExists('party_types');
    }
};
