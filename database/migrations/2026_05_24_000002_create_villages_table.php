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
        Schema::create('villages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('world_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('region');
            $table->double('x')->default(0);
            $table->double('y')->default(0);
            $table->double('land_yield');
            $table->double('technology')->default(1);
            $table->unsignedInteger('carrying_capacity')->default(0);
            $table->jsonb('culture');               // the culture vector
            $table->jsonb('stockpile')->nullable();  // communal granary: resource => amount
            $table->jsonb('state')->nullable();      // flags, cited event ids, famine/outbreak markers
            $table->timestamps();
            $table->unique(['world_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('villages');
    }
};
