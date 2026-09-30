<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('achievements')) {
            Schema::create('achievements', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->text('name');
                $table->text('description')->nullable();
                $table->text('icon_url')->nullable();
                $table->timestampTz('created_at')->useCurrent();
            });
        }

        if (! Schema::hasTable('rewards')) {
            Schema::create('rewards', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->text('name');
                $table->text('image_url')->nullable();
                $table->text('type')->nullable()->default('visual');
                $table->timestampTz('created_at')->useCurrent();
            });
        }

        $this->addMissingActivityColumns();

        if (DB::getDriverName() === 'pgsql') {
            $this->configureSupabasePolicies();
        }
    }

    public function down(): void
    {
        // These Supabase tables and columns may be managed outside Laravel.
    }

    private function addMissingActivityColumns(): void
    {
        if (! Schema::hasColumn('activities', 'cover_image_url')) {
            Schema::table('activities', function (Blueprint $table): void {
                $table->text('cover_image_url')->nullable();
            });
        }

        if (! Schema::hasColumn('activities', 'internal_media_url')) {
            Schema::table('activities', function (Blueprint $table): void {
                $table->text('internal_media_url')->nullable();
            });
        }

        if (! Schema::hasColumn('activities', 'achievement_id')) {
            Schema::table('activities', function (Blueprint $table): void {
                $table->foreignId('achievement_id')
                    ->nullable()
                    ->constrained('achievements')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('activities', 'reward_item_id')) {
            Schema::table('activities', function (Blueprint $table): void {
                $table->foreignId('reward_item_id')
                    ->nullable()
                    ->constrained('rewards')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('activities', 'unlock_after')) {
            Schema::table('activities', function (Blueprint $table): void {
                $table->integer('unlock_after')->nullable()->default(0);
            });
        }

        if (! Schema::hasColumn('activities', 'time_limit_seconds')) {
            Schema::table('activities', function (Blueprint $table): void {
                $table->integer('time_limit_seconds')->nullable();
            });
        }

        if (! Schema::hasColumn('activities', 'config')) {
            Schema::table('activities', function (Blueprint $table): void {
                $table->jsonb('config')->nullable()->default('{}');
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE public.activities ALTER COLUMN unlock_after TYPE integer USING unlock_after::integer');
            DB::statement('ALTER TABLE public.activities ALTER COLUMN time_limit_seconds TYPE integer USING time_limit_seconds::integer');
            DB::statement('ALTER TABLE public.activities ALTER COLUMN unlock_after DROP NOT NULL');
            DB::statement('ALTER TABLE public.activities ALTER COLUMN config DROP NOT NULL');
        }
    }

    private function configureSupabasePolicies(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.achievements ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.rewards ENABLE ROW LEVEL SECURITY;

            DO $$ BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_policies
                    WHERE schemaname = 'public' AND tablename = 'achievements'
                      AND policyname = 'Achievements are readable'
                ) THEN
                    CREATE POLICY "Achievements are readable" ON public.achievements
                        FOR SELECT USING (true);
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM pg_policies
                    WHERE schemaname = 'public' AND tablename = 'achievements'
                      AND policyname = 'Admins manage achievements'
                ) THEN
                    CREATE POLICY "Admins manage achievements" ON public.achievements
                        FOR ALL USING (public.has_role((SELECT auth.uid()), 'admin'))
                        WITH CHECK (public.has_role((SELECT auth.uid()), 'admin'));
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM pg_policies
                    WHERE schemaname = 'public' AND tablename = 'rewards'
                      AND policyname = 'Rewards are readable'
                ) THEN
                    CREATE POLICY "Rewards are readable" ON public.rewards
                        FOR SELECT USING (true);
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM pg_policies
                    WHERE schemaname = 'public' AND tablename = 'rewards'
                      AND policyname = 'Admins manage rewards'
                ) THEN
                    CREATE POLICY "Admins manage rewards" ON public.rewards
                        FOR ALL USING (public.has_role((SELECT auth.uid()), 'admin'))
                        WITH CHECK (public.has_role((SELECT auth.uid()), 'admin'));
                END IF;
            END $$;
            SQL);
    }
};
