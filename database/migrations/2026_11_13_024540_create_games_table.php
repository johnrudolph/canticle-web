<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('code', 6)->unique();
            $table->string('status')->default('lobby');
            $table->unsignedTinyInteger('wanderer_count');
            $table->boolean('golem_enabled')->default(false);
            $table->unsignedTinyInteger('round')->nullable();
            $table->string('phase')->nullable();
            $table->json('state')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
