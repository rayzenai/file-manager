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
        if (!Schema::hasTable('media_metadata')) {
            Schema::create('media_metadata', function (Blueprint $table) {
                $table->id();
                $table->morphs('mediable');
                $table->string('mediable_field');
                $table->string('file_name');
                $table->unsignedBigInteger('file_size');
                $table->string('mime_type')->nullable();
                $table->unsignedInteger('width')->nullable();
                $table->unsignedInteger('height')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                
                $table->index(['mediable_type', 'mediable_id', 'mediable_field'], 'media_metadata_composite_index');
                $table->index('file_size');
                $table->index('mime_type');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_metadata');
    }
};