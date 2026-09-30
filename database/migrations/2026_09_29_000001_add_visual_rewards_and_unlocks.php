<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('subjects', 'image_url')) {
            Schema::table('subjects', function (Blueprint $table): void {
                $table->text('image_url')->nullable();
            });
        }

        if (! Schema::hasColumn('activities', 'badge_name')) {
            Schema::table('activities', function (Blueprint $table): void {
                $table->string('badge_name', 80)->nullable();
            });
        }

        if (! Schema::hasColumn('activities', 'unlock_after')) {
            Schema::table('activities', function (Blueprint $table): void {
                $table->integer('unlock_after')->nullable()->default(0);
            });
        }
    }

    public function down(): void
    {
        // These columns may be pre-existing Supabase schema; rollback preserves them.
    }
};
