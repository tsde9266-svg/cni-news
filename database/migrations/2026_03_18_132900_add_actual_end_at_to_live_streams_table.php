<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('live_streams', function (Blueprint $table) {
            $table->timestamp('actual_end_at')->nullable()->after('actual_start_at');
        });
    }

    public function down()
    {
        Schema::table('live_streams', function (Blueprint $table) {
            $table->dropColumn('actual_end_at');
        });
    }
};
