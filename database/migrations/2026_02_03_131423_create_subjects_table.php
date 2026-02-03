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
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique()->comment('Subject key (toan, ly, hoa, etc.)');
            $table->string('name', 100)->comment('Subject name (Toán, Lý, Hóa, etc.)');
            $table->timestamps();

            $table->index('key', 'idx_subjects_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};
