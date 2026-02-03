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
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('sbd', 20)->unique()->comment('Số báo danh - unique identifier');
            $table->string('ma_ngoai_ngu', 10)->nullable()->comment('Mã ngoại ngữ (N1, N2, etc.)');
            $table->decimal('group_a_score', 5, 2)->nullable()->comment('Tổng điểm Khối A (Toán + Lý + Hóa)');
            $table->timestamps();

            // Indexes for performance
            $table->index('sbd', 'idx_students_sbd');
            $table->index('group_a_score', 'idx_students_group_a');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
