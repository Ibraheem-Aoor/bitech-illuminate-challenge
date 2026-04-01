<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->float('lat', precision: 10);
            $table->float('lng', precision: 10);
            $table->string('code');
            $table->timestamp('occurred_at')->nullable();
            $table->json('metadata')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
