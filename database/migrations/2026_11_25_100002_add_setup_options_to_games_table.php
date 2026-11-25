<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->boolean('exclude_advanced_locations')->default(false);
            $table->boolean('emperor_picks_starting_locations')->default(false);
            $table->json('starting_location_keys')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['exclude_advanced_locations', 'emperor_picks_starting_locations', 'starting_location_keys']);
        });
    }
};
