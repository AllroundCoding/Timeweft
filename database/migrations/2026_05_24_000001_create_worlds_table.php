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
        Schema::create('worlds', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->unsignedBigInteger('seed');          // the RNG seed the world replays from
            $table->unsignedBigInteger('tick')->default(0); // the canonical clock
            // World-level ledgers (small, written at checkpoint cadence): pair => standing,
            // pair => {ageYears,lastYear}, and the authored beats the director steers toward.
            $table->jsonb('relations')->nullable();
            $table->jsonb('routes')->nullable();
            $table->jsonb('milestones')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worlds');
    }
};
