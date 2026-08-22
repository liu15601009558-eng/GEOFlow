<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\TargetSite;
use App\Services\GeoFlow\ArticleTrackingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TrackArticleIndexJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 创建新任务实例。
     */
    public function __construct(
        public readonly int $articleId,
        public readonly ?int $siteId = null,
        public readonly string $searchEngine = 'baidu'
    ) {}

    /**
     * 执行任务。
     */
    public function handle(ArticleTrackingService $trackingService): void
    {
        $article = Article::query()->find($this->articleId);
        if (!$article) {
            return;
        }

        $sites = $this->siteId
            ? TargetSite::query()->whereKey($this->siteId)->get()
            : TargetSite::query()->where('status', 'active')->get();

        foreach ($sites as $site) {
            $trackingService->checkIndexStatus($article, $site, $this->searchEngine);
        }
    }
}
