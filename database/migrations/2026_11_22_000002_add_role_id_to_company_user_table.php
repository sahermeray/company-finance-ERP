<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_user', function (Blueprint $table): void {
            $table->foreignId('role_id')->nullable()->after('user_id')
                ->constrained('roles')
                ->nullOnDelete();
            $table->boolean('is_owner')->default(false)->after('role_id');
        });
    }

    public function down(): void
    {
        Schema::table('company_user', function (Blueprint $table): void {
            $table->dropForeign(['role_id']);
            $table->dropColumn(['role_id', 'is_owner']);
        });
    }
};
