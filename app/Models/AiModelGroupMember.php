<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiModelGroupMember extends Model
{
    protected $table = 'ai_model_group_members';

    protected $fillable = [
        'group_id',
        'model_id',
        'priority',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'group_id' => 'integer',
            'model_id' => 'integer',
            'priority' => 'integer',
            'is_primary' => 'boolean',
        ];
    }

    /**
     * 所属模型组。
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(AiModelGroup::class, 'group_id');
    }

    /**
     * 关联的 AI 模型。
     */
    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }
}
