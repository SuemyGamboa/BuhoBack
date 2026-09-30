<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            if (! Schema::hasColumn('profiles', 'plan')) {
                $table->string('plan')->default('free');
            }
            if (! Schema::hasColumn('profiles', 'subscription_status')) {
                $table->string('subscription_status')->default('active');
            }
            if (! Schema::hasColumn('profiles', 'stripe_customer_id')) {
                $table->string('stripe_customer_id')->nullable()->unique();
            }
            if (! Schema::hasColumn('profiles', 'stripe_subscription_id')) {
                $table->string('stripe_subscription_id')->nullable();
            }
            if (! Schema::hasColumn('profiles', 'current_period_end')) {
                $table->timestampTz('current_period_end')->nullable();
            }
            if (! Schema::hasColumn('profiles', 'trial_ends_at')) {
                $table->timestampTz('trial_ends_at')->nullable();
            }
            if (! Schema::hasColumn('profiles', 'requested_plan')) {
                $table->string('requested_plan')->nullable();
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE public.profiles
                    DROP CONSTRAINT IF EXISTS profiles_plan_check;
                ALTER TABLE public.profiles
                    ADD CONSTRAINT profiles_plan_check CHECK (plan IN ('free', 'monthly', 'yearly'));
                ALTER TABLE public.profiles
                    DROP CONSTRAINT IF EXISTS profiles_requested_plan_check;
                ALTER TABLE public.profiles
                    ADD CONSTRAINT profiles_requested_plan_check
                    CHECK (requested_plan IS NULL OR requested_plan IN ('monthly', 'yearly'));

                DROP POLICY IF EXISTS "Users update their own profile" ON public.profiles;
                DROP POLICY IF EXISTS "Users can update own profile, except billing fields" ON public.profiles;
                CREATE POLICY "Users can update own profile, except billing fields"
                    ON public.profiles
                    FOR UPDATE
                    USING ((SELECT auth.uid()) = id)
                    WITH CHECK ((SELECT auth.uid()) = id);
                REVOKE UPDATE ON public.profiles FROM authenticated;
                REVOKE UPDATE (
                    plan,
                    subscription_status,
                    stripe_customer_id,
                    stripe_subscription_id,
                    current_period_end,
                    trial_ends_at,
                    requested_plan
                ) ON public.profiles FROM authenticated;
                GRANT UPDATE (display_name, avatar_url) ON public.profiles TO authenticated;
                REVOKE SELECT ON public.profiles FROM PUBLIC, anon, authenticated;
                GRANT SELECT (id, display_name, avatar_url, age, created_at)
                    ON public.profiles TO anon, authenticated;
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP POLICY IF EXISTS "Users can update own profile, except billing fields" ON public.profiles;
                GRANT UPDATE ON public.profiles TO authenticated;
                REVOKE SELECT ON public.profiles FROM PUBLIC, anon, authenticated;
                GRANT SELECT ON public.profiles TO anon, authenticated;
                SQL);
        }

        Schema::table('profiles', function (Blueprint $table): void {
            foreach ([
                'requested_plan',
                'trial_ends_at',
                'current_period_end',
                'stripe_subscription_id',
                'stripe_customer_id',
                'subscription_status',
                'plan',
            ] as $column) {
                if (Schema::hasColumn('profiles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
