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
        // The provenance edges (doc 09): an event depended on prior events. Stored relationally so a
        // recursive CTE can walk the causal cone an edit invalidates (the CTE itself lands on Postgres).
        Schema::create('event_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('cause_event_id')->constrained('events')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['event_id', 'cause_event_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_dependencies');
    }
};
