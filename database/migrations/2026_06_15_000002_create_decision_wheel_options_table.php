<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decision_wheel_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wheel_id')->constrained('decision_wheels')->cascadeOnDelete();
            $table->string('option_name');
            $table->string('color')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decision_wheel_options');
    }
};
