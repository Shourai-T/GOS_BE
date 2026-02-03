<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('import_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('file_path')->comment('Path to uploaded CSV');
            $table->string('file_hash', 32)->comment('MD5 hash for duplicate detection');
            $table->enum('status', ['PENDING', 'PROCESSING', 'DONE', 'FAILED'])->default('PENDING');
            $table->integer('total_rows')->default(0);
            $table->integer('processed_rows')->default(0);
            $table->integer('error_rows')->default(0);
            $table->integer('last_processed_line')->default(0)->comment('For resume capability');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // Prevent duplicate imports in progress
            $table->index(['file_hash', 'status'], 'idx_import_jobs_hash_status');
        });

        // Add unique constraint for active imports (PENDING/PROCESSING)
        DB::statement(
            "CREATE UNIQUE INDEX idx_import_jobs_active ON import_jobs (file_hash, status) WHERE status IN ('PENDING', 'PROCESSING')"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_jobs');
    }
};
