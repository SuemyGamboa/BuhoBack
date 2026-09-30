<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LearningActivity;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ActivityController extends Controller
{
    public function store(Request $request, Subject $subject): JsonResponse
    {
        $request->merge(['subject_id' => $subject->getKey()]);
        $activity = LearningActivity::create($this->validatedData($request));

        return response()->json($activity, 201);
    }

    public function update(Request $request, LearningActivity $activity): JsonResponse
    {
        $activity->update($this->validatedData($request));

        return response()->json($activity->refresh());
    }

    public function destroy(LearningActivity $activity): JsonResponse
    {
        $activity->update(['is_active' => false]);

        return response()->json(['message' => 'La actividad se desactivó.']);
    }

    public function uploadCover(Request $request): JsonResponse
    {
        return $this->storeImage($request, 'activities/covers', 'cover_image_url');
    }

    public function uploadMedia(Request $request): JsonResponse
    {
        return $this->storeImage($request, 'activities/media', 'media_url');
    }

    private function storeImage(Request $request, string $directory, string $responseKey): JsonResponse
    {
        $validated = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $path = Storage::disk('public')->putFile($directory, $validated['image']);
        if ($path === false) {
            abort(500, 'No se pudo guardar la imagen.');
        }

        return response()->json([$responseKey => '/storage/'.$path], 201);
    }

    private function validatedData(Request $request): array
    {
        $gameType = $request->input('game_type');
        $configurationRules = match ($gameType) {
            'memorama' => [
                'config.time_limit' => ['nullable', 'integer', 'between:1,3600'],
                'config.pairs' => ['required', 'array', 'min:1', 'max:30'],
                'config.pairs.*.id' => ['required', 'string', 'max:100'],
                'config.pairs.*.content_a' => ['required', 'string', 'max:2048'],
                'config.pairs.*.content_b' => ['required', 'string', 'max:2048'],
                'config.pairs.*.label' => ['nullable', 'string', 'max:120'],
            ],
            'drag_drop' => [
                'config.zones' => ['required', 'array', 'min:1', 'max:50'],
                'config.zones.*.id' => ['required', 'string', 'max:100'],
                'config.zones.*.name' => ['required', 'string', 'max:120'],
                'config.zones.*.content' => ['required', 'string', 'max:2048'],
                'config.items' => ['required', 'array', 'min:1', 'max:50'],
                'config.items.*.id' => ['required', 'string', 'max:100'],
                'config.items.*.content' => ['required', 'string', 'max:2048'],
                'config.items.*.correct_zone' => ['required', 'string', 'max:100'],
            ],
            'quiz' => [
                'config.questions' => ['required', 'array', 'min:1', 'max:100'],
                'config.questions.*.id' => ['required', 'string', 'max:100'],
                'config.questions.*.question' => ['required', 'string', 'max:1000'],
                'config.questions.*.image_url' => [
                    'nullable',
                    'string',
                    'max:2048',
                    function (string $attribute, mixed $value, \Closure $fail): void {
                        if ($value !== null
                            && ! str_starts_with($value, '/storage/')
                            && filter_var($value, FILTER_VALIDATE_URL) === false) {
                            $fail('La imagen de la pregunta debe ser una URL válida o una imagen cargada.');
                        }
                    },
                ],
                'config.questions.*.options' => ['required', 'array', 'min:2', 'max:8'],
                'config.questions.*.options.*.id' => ['required', 'string', 'max:100'],
                'config.questions.*.options.*.content' => ['required', 'string', 'max:2048'],
                'config.questions.*.options.*.is_correct' => ['required', 'boolean'],
            ],
            'matching' => [
                'config.pairs' => ['required', 'array', 'min:1', 'max:50'],
                'config.pairs.*.id' => ['required', 'string', 'max:100'],
                'config.pairs.*.left' => ['required', 'string', 'max:2048'],
                'config.pairs.*.right' => ['required', 'string', 'max:2048'],
            ],
            default => [],
        };

        $validator = Validator::make($request->all(), [
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'game_type' => ['required', 'string', Rule::in([
                'puzzle',
                'memorama',
                'drag_drop',
                'quiz',
                'matching',
                'addition',
                'find_items',
                'timed_challenge',
                'build',
                'aim_select',
                'create_organize',
            ])],
            'difficulty' => ['required', 'integer', 'between:1,5'],
            'instructions' => ['required', 'string', 'max:5000'],
            'cover_image_url' => [
                'nullable',
                'string',
                'max:2048',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== null
                        && ! str_starts_with($value, '/storage/')
                        && filter_var($value, FILTER_VALIDATE_URL) === false) {
                        $fail('La portada debe ser una URL válida o una imagen cargada.');
                    }
                },
            ],
            'internal_media_url' => ['nullable', 'url', 'max:2048'],
            'badge_name' => ['nullable', 'string', 'max:80'],
            'achievement_id' => ['nullable', 'integer', 'exists:achievements,id'],
            'reward_item_id' => ['nullable', 'integer', 'exists:rewards,id'],
            'unlock_after' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'time_limit_seconds' => ['nullable', 'integer', 'between:1,3600'],
            'config' => ['nullable', 'array'],
            'content' => ['nullable', 'array'],
            'reward_stars' => ['sometimes', 'integer', 'between:0,3'],
            'reward_coins' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['sometimes', 'boolean'],
        ] + $configurationRules);

        $validator->after(function ($validator) use ($request, $gameType): void {
            $config = $request->input('config');
            if (! is_array($config)) {
                return;
            }

            if ($gameType === 'drag_drop' && is_array($config['zones'] ?? null)) {
                $zoneIds = array_column($config['zones'], 'id');
                foreach ($config['items'] ?? [] as $index => $item) {
                    if (is_array($item)
                        && isset($item['correct_zone'])
                        && ! in_array($item['correct_zone'], $zoneIds, true)) {
                        $validator->errors()->add(
                            "config.items.$index.correct_zone",
                            'Cada elemento debe apuntar a una zona destino existente.',
                        );
                    }
                }
            }

            if ($gameType === 'quiz') {
                foreach ($config['questions'] ?? [] as $questionIndex => $question) {
                    if (! is_array($question) || ! is_array($question['options'] ?? null)) {
                        continue;
                    }

                    $correctCount = count(array_filter(
                        $question['options'],
                        fn (mixed $option): bool => is_array($option)
                            && filter_var($option['is_correct'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    ));

                    if ($correctCount !== 1) {
                        $validator->errors()->add(
                            "config.questions.$questionIndex.options",
                            'Cada pregunta debe tener exactamente una respuesta correcta.',
                        );
                    }
                }
            }
        });

        $validated = $validator->validate();
        if (in_array($gameType, ['memorama', 'drag_drop', 'quiz', 'matching'], true)) {
            $validated['content'] = $validated['config'];
        }

        return $validated;
    }
}
