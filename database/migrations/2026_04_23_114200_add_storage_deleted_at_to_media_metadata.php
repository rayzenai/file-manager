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
        Schema::table('media_metadata', function (Blueprint $table) {
            $table->timestamp('storage_deleted_at')->nullable()->after('seo_title');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_metadata', function (Blueprint $table) {
            $table->dropColumn('storage_deleted_at');
        });
    }
};