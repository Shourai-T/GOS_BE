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
        Schema::create('scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->onDelete('cascade');
            $table->foreignId('subject_id')->constrained('subjects')->onDelete('cascade');
            $table->decimal('score', 4, 2)->nullable()->comment('Score (0-10, NULL if not taken)');
            $table->timestamps();

            // UNIQUE constraint for upsert (ONE student-subject pair only)
            $table->unique(['student_id', 'subject_id'], 'idx_scores_student_subject_unique');
            // Index for report queries (subject_id, score)
            $table->index(['subject_id', 'score'], 'idx_scores_subject_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scores');
    }
};
