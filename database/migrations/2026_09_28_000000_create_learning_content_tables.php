<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('display_name');
            $table->text('avatar_url')->nullable();
            $table->unsignedTinyInteger('age')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('user_roles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('user_id');
            $table->enum('role', ['admin', 'player']);
            $table->unique(['user_id', 'role']);
            $table->index('user_id', 'idx_user_roles_user');
        });

        Schema::create('subjects', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->string('color')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('game_type');
            $table->unsignedTinyInteger('difficulty')->default(1);
            $table->text('instructions')->nullable();
            $table->jsonb('content')->nullable();
            $table->unsignedTinyInteger('reward_stars')->default(1);
            $table->unsignedInteger('reward_coins')->default(10);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->index('subject_id', 'idx_activities_subject');
            if (DB::getDriverName() !== 'pgsql') {
                $table->index('is_active', 'idx_activities_active');
            }
        });

        Schema::create('player_progress', function (Blueprint $table): void {
            $table->id();
            $table->uuid('player_id');
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('stars_earned')->default(0);
            $table->unsignedInteger('coins_earned')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->boolean('is_completed')->default(false);
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['player_id', 'activity_id']);
            $table->index('player_id', 'idx_progress_player');
            $table->index('activity_id', 'idx_progress_activity');
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->configureSupabaseSchema();
        }

        DB::table('user_roles')->updateOrInsert(
            ['user_id' => '05d8cad9-0b1b-414a-a2b2-cb6dbf839266', 'role' => 'admin'],
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS on_auth_user_created ON auth.users;
                DROP FUNCTION IF EXISTS public.handle_new_user();
                DROP FUNCTION IF EXISTS public.has_role(uuid, public.app_role);
                DROP POLICY IF EXISTS "Public profiles are readable" ON public.profiles;
                DROP POLICY IF EXISTS "Users insert their own profile" ON public.profiles;
                DROP POLICY IF EXISTS "Users update their own profile" ON public.profiles;
                DROP POLICY IF EXISTS "Users and admins read roles" ON public.user_roles;
                DROP POLICY IF EXISTS "Admins manage roles" ON public.user_roles;
                DROP POLICY IF EXISTS "Active subjects are readable" ON public.subjects;
                DROP POLICY IF EXISTS "Admins manage subjects" ON public.subjects;
                DROP POLICY IF EXISTS "Active activities are readable" ON public.activities;
                DROP POLICY IF EXISTS "Admins manage activities" ON public.activities;
                DROP POLICY IF EXISTS "Players read their own progress" ON public.player_progress;
                DROP POLICY IF EXISTS "Players create their own progress" ON public.player_progress;
                DROP POLICY IF EXISTS "Players update their own progress" ON public.player_progress;
                ALTER TABLE public.player_progress DISABLE ROW LEVEL SECURITY;
                ALTER TABLE public.activities DISABLE ROW LEVEL SECURITY;
                ALTER TABLE public.subjects DISABLE ROW LEVEL SECURITY;
                ALTER TABLE public.user_roles DISABLE ROW LEVEL SECURITY;
                ALTER TABLE public.profiles DISABLE ROW LEVEL SECURITY;
                SQL);
        }

        Schema::dropIfExists('player_progress');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('profiles');
    }

    private function configureSupabaseSchema(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$ BEGIN
                CREATE TYPE public.app_role AS ENUM ('admin', 'player');
            EXCEPTION WHEN duplicate_object THEN NULL;
            END $$;

            ALTER TABLE public.user_roles
                ALTER COLUMN role TYPE public.app_role
                USING role::text::public.app_role;

            ALTER TABLE public.profiles
                ADD CONSTRAINT profiles_id_auth_users_fk
                FOREIGN KEY (id) REFERENCES auth.users(id) ON DELETE CASCADE;
            ALTER TABLE public.user_roles
                ADD CONSTRAINT user_roles_user_id_auth_users_fk
                FOREIGN KEY (user_id) REFERENCES auth.users(id) ON DELETE CASCADE;
            ALTER TABLE public.player_progress
                ADD CONSTRAINT player_progress_player_id_auth_users_fk
                FOREIGN KEY (player_id) REFERENCES auth.users(id) ON DELETE CASCADE;

            ALTER TABLE public.profiles
                ADD CONSTRAINT profiles_age_range CHECK (age IS NULL OR age BETWEEN 5 AND 11);
            ALTER TABLE public.activities
                ADD CONSTRAINT activities_difficulty_range CHECK (difficulty BETWEEN 1 AND 5);
            ALTER TABLE public.player_progress
                ADD CONSTRAINT player_progress_stars_range CHECK (stars_earned BETWEEN 0 AND 3);

            CREATE INDEX idx_activities_active
                ON public.activities(is_active)
                WHERE is_active = true;

            CREATE OR REPLACE FUNCTION public.has_role(_user_id uuid, _role public.app_role)
            RETURNS boolean
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = public
            AS $$
                SELECT EXISTS (
                    SELECT 1 FROM public.user_roles
                    WHERE user_id = _user_id AND role = _role
                );
            $$;

            CREATE OR REPLACE FUNCTION public.handle_new_user()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public
            AS $$
            BEGIN
                INSERT INTO public.profiles (id, display_name, avatar_url, created_at)
                VALUES (
                    NEW.id,
                    COALESCE(NEW.raw_user_meta_data ->> 'display_name', split_part(NEW.email, '@', 1)),
                    NEW.raw_user_meta_data ->> 'avatar_url',
                    NOW()
                )
                ON CONFLICT (id) DO NOTHING;
                RETURN NEW;
            END;
            $$;

            DROP TRIGGER IF EXISTS on_auth_user_created ON auth.users;
            CREATE TRIGGER on_auth_user_created
                AFTER INSERT ON auth.users
                FOR EACH ROW EXECUTE FUNCTION public.handle_new_user();

            ALTER TABLE public.profiles ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.user_roles ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.subjects ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.activities ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.player_progress ENABLE ROW LEVEL SECURITY;

            CREATE POLICY "Public profiles are readable" ON public.profiles
                FOR SELECT USING (true);
            CREATE POLICY "Users insert their own profile" ON public.profiles
                FOR INSERT
                WITH CHECK (id = (SELECT auth.uid()));
            CREATE POLICY "Users update their own profile" ON public.profiles
                FOR UPDATE USING (id = (SELECT auth.uid()))
                WITH CHECK (id = (SELECT auth.uid()));
            CREATE POLICY "Users and admins read roles" ON public.user_roles
                FOR SELECT USING (
                    user_id = (SELECT auth.uid())
                    OR public.has_role((SELECT auth.uid()), 'admin')
                );
            CREATE POLICY "Admins manage roles" ON public.user_roles
                FOR ALL USING (public.has_role((SELECT auth.uid()), 'admin'))
                WITH CHECK (public.has_role((SELECT auth.uid()), 'admin'));
            CREATE POLICY "Active subjects are readable" ON public.subjects
                FOR SELECT USING (
                    is_active OR public.has_role((SELECT auth.uid()), 'admin')
                );
            CREATE POLICY "Admins manage subjects" ON public.subjects
                FOR ALL USING (public.has_role((SELECT auth.uid()), 'admin'))
                WITH CHECK (public.has_role((SELECT auth.uid()), 'admin'));
            CREATE POLICY "Active activities are readable" ON public.activities
                FOR SELECT USING (
                    is_active OR public.has_role((SELECT auth.uid()), 'admin')
                );
            CREATE POLICY "Admins manage activities" ON public.activities
                FOR ALL USING (public.has_role((SELECT auth.uid()), 'admin'))
                WITH CHECK (public.has_role((SELECT auth.uid()), 'admin'));
            CREATE POLICY "Players read their own progress" ON public.player_progress
                FOR SELECT USING (
                    player_id = (SELECT auth.uid())
                    AND public.has_role((SELECT auth.uid()), 'player')
                );
            CREATE POLICY "Players create their own progress" ON public.player_progress
                FOR INSERT WITH CHECK (
                    player_id = (SELECT auth.uid())
                    AND public.has_role((SELECT auth.uid()), 'player')
                );
            CREATE POLICY "Players update their own progress" ON public.player_progress
                FOR UPDATE USING (
                    player_id = (SELECT auth.uid())
                    AND public.has_role((SELECT auth.uid()), 'player')
                )
                WITH CHECK (
                    player_id = (SELECT auth.uid())
                    AND public.has_role((SELECT auth.uid()), 'player')
                );
            SQL);
    }
};
