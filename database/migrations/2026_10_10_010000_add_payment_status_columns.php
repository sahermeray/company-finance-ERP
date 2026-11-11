<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('status', 30)->default('posted')->after('method');
            $table->dateTime('posted_at')->nullable()->after('status');
            $table->index(['company_id', 'status'], 'payments_company_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_company_status_idx');
            $table->dropColumn(['status', 'posted_at']);
        });
    }
};
