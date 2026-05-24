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
        Schema::table('villages', function (Blueprint $table) {
            // A folded (LOD) settlement's statistical stand-in (TWT-213/247): the cohort's age-band
            // distribution and mean sickness as a queryable bag. Null for a tracked, per-agent settlement.
            $table->jsonb('cohort')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('villages', function (Blueprint $table) {
            $table->dropColumn('cohort');
        });
    }
};
