<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Achievement;
use App\Models\Reward;
use Illuminate\Http\JsonResponse;

class ActivityCatalogController extends Controller
{
    public function achievements(): JsonResponse
    {
        return response()->json(Achievement::query()->orderBy('name')->get());
    }

    public function rewards(): JsonResponse
    {
        return response()->json(Reward::query()->orderBy('name')->get());
    }
}
