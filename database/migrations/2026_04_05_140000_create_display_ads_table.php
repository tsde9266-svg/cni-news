<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('display_ads', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('image_url');
            $table->string('click_url');
            $table->string('alt_text')->nullable();
            // placement: leaderboard | sidebar | in-feed | all
            $table->string('placement')->default('all');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
        });

        // Seed the first real ad right inside the migration so it works immediately
        \DB::table('display_ads')->insert([
            'title'         => 'Pak Mecca Meats',
            'image_url'     => 'https://api.cninews.tv/adv1.png',
            'click_url'     => 'https://www.facebook.com/PakMeccaMeats',
            'alt_text'      => 'Pak Mecca Meats — Premium Halal Butcher',
            'placement'     => 'all',
            'is_active'     => true,
            'display_order' => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('display_ads');
    }
};
