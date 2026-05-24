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
        // The durable form of the in-memory Checkpoint (TWT-32): a world's boundary state at a tick,
        // from which it resumes byte-identically. The relational rows are the queryable projection;
        // this is the exact-resume payload (load() reads the latest and replays from it).
        Schema::create('world_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('world_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('tick');
            $table->longText('boundary_state'); // a serialized Checkpoint (seed + tick + snapshot)
            $table->timestamps();
            $table->index(['world_id', 'tick']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('world_checkpoints');
    }
};
