<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('golem_cards', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->boolean('print')->default(false);
            $table->string('name');
            $table->text('action')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('golem_cards');
    }
};
