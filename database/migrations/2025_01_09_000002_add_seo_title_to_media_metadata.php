<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('media_metadata')) {
            Schema::table('media_metadata', function (Blueprint $table) {
                $table->string('seo_title', 160)->nullable()->after('metadata');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('media_metadata')) {
            Schema::table('media_metadata', function (Blueprint $table) {
                $table->dropColumn('seo_title');
            });
        }
    }
};