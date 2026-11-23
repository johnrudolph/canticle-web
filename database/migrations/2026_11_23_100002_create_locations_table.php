<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->boolean('print')->default(false);
            $table->string('name');
            $table->text('lore')->nullable();
            $table->text('rule')->nullable();
            $table->string('cost_1')->nullable();
            $table->string('cost_2')->nullable();
            $table->string('cost_3')->nullable();
            $table->string('cost_4')->nullable();
            $table->string('produces')->nullable();
            $table->string('produces_emoji')->nullable();
            $table->string('relics')->nullable();
            $table->text('relic_names')->nullable();
            $table->boolean('is_starter')->default(false);
            $table->boolean('has_shard')->default(false);
            $table->boolean('has_tree')->default(false);
            $table->boolean('has_back_text')->default(false);
            $table->boolean('has_relics')->default(false);
            $table->boolean('has_specific_relics')->default(false);
            $table->boolean('produces_anything')->default(false);
            $table->boolean('has_cost')->default(false);
            $table->string('cost_emoji')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
