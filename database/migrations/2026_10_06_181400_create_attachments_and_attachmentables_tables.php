<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk')->default('local');
            $table->text('path');
            $table->string('original_filename');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum', 64)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'created_at'], 'attachments_company_date_idx');
        });

        Schema::create('attachmentables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attachment_id')->constrained()->cascadeOnDelete();
            $table->morphs('attachable');
            $table->timestamps();
            $table->unique(
                ['attachment_id', 'attachable_type', 'attachable_id'],
                'attachmentables_attachment_owner_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachmentables');
        Schema::dropIfExists('attachments');
    }
};
