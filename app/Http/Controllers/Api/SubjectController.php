<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubjectController extends Controller
{
    public function publicIndex(): JsonResponse
    {
        return response()->json(
            Subject::query()
                ->where('is_active', true)
                ->with(['activities' => fn ($query) => $query
                    ->where('is_active', true)
                    ->orderBy('sort_order')])
                ->orderBy('sort_order')
                ->get()
        );
    }

    public function index(): JsonResponse
    {
        return response()->json(
            Subject::query()
                ->with('activities')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $subject = Subject::create($this->validatedData($request));

        return response()->json($subject, 201);
    }

    public function update(Request $request, Subject $subject): JsonResponse
    {
        $subject->update($this->validatedData($request));

        return response()->json($subject->refresh());
    }

    public function destroy(Subject $subject): JsonResponse
    {
        $subject->update(['is_active' => false]);

        return response()->json(['message' => 'La materia se desactivó.']);
    }

    private function validatedData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'icon' => ['nullable', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ]);
    }
}
