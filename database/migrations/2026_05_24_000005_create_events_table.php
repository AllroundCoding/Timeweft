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
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('world_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sim_id');      // the in-sim ChronicleEvent id (unique within a world)
            $table->integer('tick');
            $table->string('type');
            $table->text('text');
            $table->jsonb('subjects')->nullable();  // agent ids the event concerns
            $table->jsonb('factors')->nullable();   // typed non-event preconditions (famine, old-age…)
            $table->timestamps();
            $table->unique(['world_id', 'sim_id']);
            $table->index(['world_id', 'tick']);    // range-query a deep, centuries-long timeline
            $table->index(['world_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
