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
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('world_id')->constrained()->cascadeOnDelete();
            $table->foreignId('village_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('sim_id');      // the in-sim agent id (unique within a world)
            $table->string('name');
            $table->string('species');              // forward-compatible to data-driven species/races (TWT-201)
            $table->string('sex', 1);
            $table->integer('birth_tick');          // signed — founders are born before tick 0
            $table->integer('death_tick')->nullable();
            $table->boolean('alive')->default(true);
            $table->double('money')->default(0);
            $table->string('profession')->nullable();
            // Flexible bags: traits, and needs as {name: {value, capacity}} — variable per species,
            // nullable by absence, room for explicit per-need capacity without a migration.
            $table->jsonb('traits');
            $table->jsonb('needs');
            $table->jsonb('job_history')->nullable();
            $table->jsonb('parent_ids')->nullable();
            $table->timestamps();
            $table->unique(['world_id', 'sim_id']);
            $table->index(['world_id', 'alive']);   // load the living working set; the dead rest in the DB
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
