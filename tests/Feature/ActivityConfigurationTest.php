<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePaidAdmin;
use App\Http\Middleware\EnsureSupabaseAdmin;
use Database\Seeders\ContentDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ActivityConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsurePaidAdmin::class);
    }

    public function test_admin_catalogs_return_seeded_achievements_and_rewards(): void
    {
        $this->seed(ContentDemoSeeder::class);
        $this->withoutMiddleware(EnsureSupabaseAdmin::class);

        $this->getJson('/api/admin/achievements')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Memoria brillante']);
        $this->getJson('/api/admin/rewards')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Sombrero de explorador']);
    }

    public function test_it_stores_an_uploaded_activity_cover_on_the_public_disk(): void
    {
        Storage::fake('public');
        $this->withoutMiddleware(EnsureSupabaseAdmin::class);

        $response = $this->post('/api/admin/activities/cover', [
            'image' => UploadedFile::fake()->createWithContent(
                'cover.png',
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jGZkAAAAASUVORK5CYII='),
            ),
        ]);

        $response->assertCreated();
        $path = str_replace('/storage/', '', $response->json('cover_image_url'));
        Storage::disk('public')->assertExists($path);
    }

    public function test_it_stores_uploaded_game_content_images_on_the_public_disk(): void
    {
        Storage::fake('public');
        $this->withoutMiddleware(EnsureSupabaseAdmin::class);

        $response = $this->post('/api/admin/activities/media', [
            'image' => UploadedFile::fake()->createWithContent(
                'number.png',
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jGZkAAAAASUVORK5CYII='),
            ),
        ]);

        $response->assertCreated();
        $path = str_replace('/storage/', '', $response->json('media_url'));
        $this->assertStringStartsWith('activities/media/', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_memorama_requires_pairs_with_both_contents(): void
    {
        $subjectId = DB::table('subjects')->insertGetId([
            'name' => 'Ciencias',
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $this->withoutMiddleware(EnsureSupabaseAdmin::class);

        $this->postJson("/api/admin/subjects/$subjectId/activities", [
            'name' => 'Memorama de animales',
            'game_type' => 'memorama',
            'difficulty' => 1,
            'instructions' => 'Encuentra las parejas.',
            'config' => [
                'time_limit' => 60,
                'pairs' => [
                    ['id' => 'p1', 'content_a' => '🐶', 'content_b' => '', 'label' => 'Animal'],
                ],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('config.pairs.0.content_b');
    }

    public function test_it_saves_memorama_configuration_rewards_and_cover_metadata(): void
    {
        $subjectId = DB::table('subjects')->insertGetId([
            'name' => 'Matemáticas',
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $achievementId = DB::table('achievements')->insertGetId(['name' => 'Memoria brillante']);
        $rewardId = DB::table('rewards')->insertGetId(['name' => 'Pegatina de estrella']);
        $this->withoutMiddleware(EnsureSupabaseAdmin::class);

        $this->postJson("/api/admin/subjects/$subjectId/activities", [
            'name' => 'Memorama de animales',
            'game_type' => 'memorama',
            'difficulty' => 2,
            'instructions' => 'Encuentra las parejas.',
            'cover_image_url' => '/storage/activities/covers/portada.png',
            'achievement_id' => $achievementId,
            'badge_name' => 'Memoria brillante',
            'reward_item_id' => $rewardId,
            'time_limit_seconds' => 60,
            'config' => [
                'time_limit' => 60,
                'pairs' => [
                    ['id' => 'p1', 'content_a' => '🐶', 'content_b' => 'Perro', 'label' => 'Animal'],
                    ['id' => 'p2', 'content_a' => '2+2', 'content_b' => '4', 'label' => 'Suma'],
                ],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('config.pairs.0.content_a', '🐶')
            ->assertJsonPath('content.pairs.1.content_b', '4')
            ->assertJsonPath('cover_image_url', '/storage/activities/covers/portada.png')
            ->assertJsonPath('achievement_id', $achievementId)
            ->assertJsonPath('reward_item_id', $rewardId);
    }

    public function test_drag_drop_requires_existing_destination_zone_ids(): void
    {
        $subjectId = DB::table('subjects')->insertGetId([
            'name' => 'Ciencias',
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $this->withoutMiddleware(EnsureSupabaseAdmin::class);

        $this->postJson("/api/admin/subjects/$subjectId/activities", [
            'name' => 'Clasifica objetos',
            'game_type' => 'drag_drop',
            'difficulty' => 1,
            'instructions' => 'Lleva cada objeto a su zona.',
            'config' => [
                'zones' => [['id' => 'z1', 'name' => 'Frutas', 'content' => '🍇']],
                'items' => [['id' => 'i1', 'content' => '🥕', 'correct_zone' => 'missing']],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('config.items.0.correct_zone');
    }

    public function test_quiz_requires_one_correct_option_and_stores_visual_content(): void
    {
        $subjectId = DB::table('subjects')->insertGetId([
            'name' => 'Matemáticas',
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $this->withoutMiddleware(EnsureSupabaseAdmin::class);

        $payload = [
            'name' => 'Suma sencilla',
            'game_type' => 'quiz',
            'difficulty' => 1,
            'instructions' => 'Elige el resultado correcto.',
            'config' => [
                'questions' => [[
                    'id' => 'q1',
                    'question' => '¿Cuánto es 2+2?',
                    'image_url' => null,
                    'options' => [
                        ['id' => 'o1', 'content' => '3', 'is_correct' => false],
                        ['id' => 'o2', 'content' => '4', 'is_correct' => true],
                        ['id' => 'o3', 'content' => 'https://example.test/five.png', 'is_correct' => false],
                    ],
                ]],
            ],
        ];

        $this->postJson("/api/admin/subjects/$subjectId/activities", [
            ...$payload,
            'config' => [
                ...$payload['config'],
                'questions' => [[
                    ...$payload['config']['questions'][0],
                    'options' => array_map(
                        fn (array $option): array => [...$option, 'is_correct' => false],
                        $payload['config']['questions'][0]['options'],
                    ),
                ]],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('config.questions.0.options');

        $this->postJson("/api/admin/subjects/$subjectId/activities", $payload)
            ->assertCreated()
            ->assertJsonPath('content.questions.0.options.2.content', 'https://example.test/five.png')
            ->assertJsonPath('content.questions.0.options.1.is_correct', true);
    }

    public function test_matching_saves_text_and_url_pairs_in_content(): void
    {
        $subjectId = DB::table('subjects')->insertGetId([
            'name' => 'Inglés',
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $this->withoutMiddleware(EnsureSupabaseAdmin::class);

        $this->postJson("/api/admin/subjects/$subjectId/activities", [
            'name' => 'Une las parejas',
            'game_type' => 'matching',
            'difficulty' => 2,
            'instructions' => 'Relaciona cada palabra.',
            'config' => [
                'pairs' => [
                    ['id' => 'm1', 'left' => '🐶', 'right' => 'Dog'],
                    ['id' => 'm2', 'left' => 'https://example.test/cat.png', 'right' => 'Cat'],
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('content.pairs.0.right', 'Dog')
            ->assertJsonPath('content.pairs.1.left', 'https://example.test/cat.png');
    }
}
