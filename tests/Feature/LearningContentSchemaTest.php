<?php

namespace Tests\Feature;

use Database\Seeders\ContentDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LearningContentSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_learning_content_supports_subject_artwork_and_activity_rewards_and_unlocks(): void
    {
        $this->assertTrue(Schema::hasColumns('profiles', [
            'plan',
            'subscription_status',
            'stripe_customer_id',
            'stripe_subscription_id',
            'current_period_end',
            'trial_ends_at',
            'requested_plan',
        ]));
        $this->assertTrue(Schema::hasColumn('subjects', 'image_url'));
        $this->assertTrue(Schema::hasColumns('activities', [
            'badge_name',
            'unlock_after',
            'content',
            'reward_stars',
            'reward_coins',
            'cover_image_url',
            'internal_media_url',
            'achievement_id',
            'reward_item_id',
            'time_limit_seconds',
            'config',
        ]));
        $this->assertTrue(Schema::hasColumns('achievements', ['name', 'description', 'icon_url']));
        $this->assertTrue(Schema::hasColumns('rewards', ['name', 'image_url', 'type']));
        $this->assertSame('integer', Schema::getColumnType('activities', 'unlock_after'));
        $this->assertSame('integer', Schema::getColumnType('activities', 'time_limit_seconds'));
        $columns = collect(Schema::getColumns('activities'))->keyBy('name');
        $this->assertTrue($columns['unlock_after']['nullable']);
        $this->assertTrue($columns['config']['nullable']);
        $rewardColumns = collect(Schema::getColumns('rewards'))->keyBy('name');
        $this->assertTrue($rewardColumns['type']['nullable']);

        DB::table('achievements')->insert([
            ['name' => 'Logro repetible'],
            ['name' => 'Logro repetible'],
        ]);
        $rewardId = DB::table('rewards')->insertGetId(['name' => 'Recompensa predeterminada']);
        $this->assertSame(
            'visual',
            DB::table('rewards')->where('id', $rewardId)->value('type'),
        );

        $subjectId = DB::table('subjects')->insertGetId([
            'name' => 'Matemáticas',
            'description' => 'Actividades matemáticas básicas.',
            'image_url' => 'https://example.test/matematicas.png',
            'icon' => 'calculate',
            'color' => '#4d96ff',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $activityId = DB::table('activities')->insertGetId([
            'subject_id' => $subjectId,
            'name' => 'Pares de números',
            'game_type' => 'memorama',
            'difficulty' => 2,
            'badge_name' => 'Memorista',
            'unlock_after' => 1,
            'config' => json_encode([
                'pair_count' => 2,
                'cards' => [
                    ['emoji' => '🐶', 'text' => 'Perro'],
                    ['emoji' => '🐱', 'text' => 'Gato'],
                ],
            ]),
            'time_limit_seconds' => 60,
            'content' => json_encode([
                'reward_visual' => [
                    'name' => 'Capa estelar',
                    'image_url' => 'https://example.test/capa.png',
                ],
            ]),
            'reward_stars' => 2,
            'reward_coins' => 15,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->assertSame(
            'Capa estelar',
            DB::table('activities')->where('id', $activityId)->value('content->reward_visual->name'),
        );
        $this->assertSame(1, DB::table('activities')->where('id', $activityId)->value('unlock_after'));
        $this->assertSame(
            2,
            DB::table('activities')->where('id', $activityId)->value('config->pair_count'),
        );
    }

    public function test_demo_seeder_creates_three_repeatable_subjects_with_five_simple_activities_each(): void
    {
        $this->seed(ContentDemoSeeder::class);
        $this->seed(ContentDemoSeeder::class);

        $subject = DB::table('subjects')
            ->where('name', 'Matemáticas de prueba')
            ->first();

        $this->assertNotNull($subject);
        $this->assertNull($subject->image_url);
        $this->assertSame(
            5,
            DB::table('activities')
                ->where('subject_id', $subject->id)
                ->where('is_active', true)
                ->count(),
        );
        $this->assertSame(3, DB::table('subjects')->where('is_active', true)->count());
        $this->assertSame(
            15,
            DB::table('activities')->where('is_active', true)->count(),
        );
        foreach (['Español', 'Inglés'] as $subjectName) {
            $this->assertSame(
                5,
                DB::table('activities')
                    ->join('subjects', 'activities.subject_id', '=', 'subjects.id')
                    ->whereRaw('LOWER(subjects.name) = ?', [mb_strtolower($subjectName)])
                    ->where('activities.is_active', true)
                    ->count(),
                "{$subjectName} must have five active demo activities.",
            );
        }
        $this->assertEqualsCanonicalizing(
            ['memorama', 'drag_drop', 'quiz', 'matching'],
            DB::table('activities')
                ->where('subject_id', $subject->id)
                ->where('is_active', true)
                ->distinct()
                ->pluck('game_type')
                ->all(),
        );
        $memoramaContent = json_decode(
            DB::table('activities')->where('name', 'Memorama de números')->value('content'),
            true,
        );
        $quizContent = json_decode(
            DB::table('activities')->where('name', 'Cuenta las manzanas')->value('content'),
            true,
        );
        $this->assertSame('Uno', $memoramaContent['pairs'][0]['content_b']);
        $this->assertTrue($quizContent['questions'][0]['options'][1]['is_correct']);
        $this->assertSame(3, DB::table('achievements')->count());
        $this->assertSame(3, DB::table('rewards')->count());
    }

    public function test_activity_schema_migration_is_safe_to_run_again_with_supabase_objects_present(): void
    {
        $migration = require database_path('migrations/2026_09_29_000002_create_activity_catalogs_and_configuration.php');
        $migration->up();

        $this->assertTrue(Schema::hasTable('achievements'));
        $this->assertTrue(Schema::hasTable('rewards'));
        $this->assertTrue(Schema::hasColumns('activities', [
            'cover_image_url',
            'internal_media_url',
            'achievement_id',
            'reward_item_id',
            'unlock_after',
            'time_limit_seconds',
            'config',
        ]));
    }
}
