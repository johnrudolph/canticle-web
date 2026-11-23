<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relics', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->boolean('print')->default(false);
            $table->string('name');
            $table->string('location')->nullable();
            $table->string('slot')->nullable();
            $table->string('type')->nullable();
            $table->string('symbol')->nullable();
            $table->text('effect')->nullable();
            $table->string('slot_emoji')->nullable();
            $table->boolean('is_action')->default(false);
            $table->boolean('is_passive')->default(false);
            $table->boolean('is_event')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relics');
    }
};
