<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blitz_poll_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('blitz_polls')->cascadeOnDelete();
            $table->string('option_name');
            $table->string('color')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blitz_poll_options');
    }
};
