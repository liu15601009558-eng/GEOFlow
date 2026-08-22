<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiModelGroup extends Model
{
    protected $table = 'ai_model_groups';

    protected $fillable = [
        'name',
        'description',
        'strategy',
        'is_active',
        'last_used_model_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_used_model_id' => 'integer',
        ];
    }

    /**
     * 模型组成员。
     */
    public function members(): HasMany
    {
        return $this->hasMany(AiModelGroupMember::class, 'group_id');
    }
}
