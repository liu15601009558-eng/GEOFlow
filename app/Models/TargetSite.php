<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 目标站点（文章分发与收录追踪使用）。
 */
class TargetSite extends Model
{
    protected $table = 'target_sites';

    protected $fillable = [
        'name',
        'url',
        'sync_token',
        'default_category_id',
        'default_keywords',
        'status',
        'image_path',
    ];

    protected function casts(): array
    {
        return [
            'default_category_id' => 'integer',
        ];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'site_id');
    }
}
