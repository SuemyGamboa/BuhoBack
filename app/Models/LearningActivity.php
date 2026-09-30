<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LearningActivity extends Model
{
    public $timestamps = false;

    protected $table = 'activities';

    protected $fillable = [
        'subject_id',
        'name',
        'description',
        'game_type',
        'difficulty',
        'instructions',
        'cover_image_url',
        'internal_media_url',
        'badge_name',
        'achievement_id',
        'reward_item_id',
        'unlock_after',
        'time_limit_seconds',
        'config',
        'content',
        'reward_stars',
        'reward_coins',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'difficulty' => 'integer',
            'unlock_after' => 'integer',
            'achievement_id' => 'integer',
            'reward_item_id' => 'integer',
            'time_limit_seconds' => 'integer',
            'config' => 'array',
            'reward_stars' => 'integer',
            'reward_coins' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function achievement(): BelongsTo
    {
        return $this->belongsTo(Achievement::class);
    }

    public function rewardItem(): BelongsTo
    {
        return $this->belongsTo(Reward::class);
    }
}
