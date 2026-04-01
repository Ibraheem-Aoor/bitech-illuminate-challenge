<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('neighborhoods', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('name')->unique();
            $table->float('centroid_lat', precision: 10);
            $table->float('centroid_lng', precision: 10);
            $table->json('boundary');
            $table->json('properties')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('neighborhoods');
    }
};
