<?php

namespace App\Services\GeoFlow;

use App\Models\Article;
use App\Models\TargetSite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;

/**
 * 文章发布效果追踪服务。
 *
 * 负责：
 * 1. 检查文章是否被搜索引擎收录；
 * 2. 查询关键词排名；
 * 3. 记录追踪历史，生成效果报告。
 */
class ArticleTrackingService
{
    /**
     * 检查单篇文章在指定站点的收录状态。
     *
     * @return array{is_indexed:bool, url:string, checked_at:Carbon}
     */
    public function checkIndexStatus(Article $article, TargetSite $site, string $searchEngine = 'baidu'): array
    {
        $articleUrl = $this->generateArticleUrl($article, $site);
        $isIndexed = $this->verifyIndexStatus($articleUrl, $searchEngine);

        DB::table('article_tracking')->upsert(
            [
                [
                    'article_id' => $article->id,
                    'site_id' => $site->id,
                    'search_engine' => $searchEngine,
                    'is_indexed' => $isIndexed,
                    'indexed_at' => $isIndexed ? Carbon::now() : null,
                    'last_checked_at' => Carbon::now(),
                    'check_status' => 'completed',
                    'updated_at' => Carbon::now(),
                ],
            ],
            ['article_id', 'site_id', 'search_engine']
        );

        return [
            'is_indexed' => $isIndexed,
            'url' => $articleUrl,
            'checked_at' => Carbon::now(),
        ];
    }

    /**
     * 批量检查任务下所有文章的收录状态。
     *
     * @return array{total:int, indexed:int, pending:int, failed:int}
     */
    public function checkTaskArticles(int $taskId, string $searchEngine = 'baidu'): array
    {
        $articles = Article::query()
            ->where('task_id', $taskId)
            ->where('status', 'published')
            ->get();

        $sites = TargetSite::query()->where('status', 'active')->get();

        $stats = ['total' => 0, 'indexed' => 0, 'pending' => 0, 'failed' => 0];

        foreach ($articles as $article) {
            foreach ($sites as $site) {
                $stats['total']++;
                try {
                    $result = $this->checkIndexStatus($article, $site, $searchEngine);
                    if ($result['is_indexed']) {
                        $stats['indexed']++;
                    } else {
                        $stats['pending']++;
                    }
                } catch (\Throwable $e) {
                    $stats['failed']++;
                }
            }
        }

        return $stats;
    }

    /**
     * 查询关键词在搜索引擎中的排名。
     *
     * @return array{rank:int|null, url:string|null, title:string|null}
     */
    public function checkKeywordRank(string $keyword, string $searchEngine = 'baidu', int $maxPages = 5): array
    {
        // 使用搜索引擎 API 或模拟搜索
        // 注意：实际生产环境应使用官方 API（如百度站长平台 API）
        $results = $this->searchKeyword($keyword, $searchEngine, $maxPages);

        return $results;
    }

    /**
     * 获取文章追踪汇总报告。
     *
     * @return array{indexed_rate:float, avg_check_age:int, by_site:array, by_status:array}
     */
    public function getTrackingReport(?int $taskId = null): array
    {
        $query = DB::table('article_tracking')
            ->selectRaw('
                COUNT(*) as total,
                COUNT(*) FILTER (WHERE is_indexed = true) as indexed,
                COUNT(*) FILTER (WHERE is_indexed = false) as not_indexed,
                AVG(EXTRACT(EPOCH FROM (NOW() - last_checked_at))/3600)::numeric(10,1) as avg_check_age_hours
            ');

        if ($taskId) {
            $query->join('articles', 'article_tracking.article_id', '=', 'articles.id')
                ->where('articles.task_id', $taskId);
        }

        $row = $query->first();

        $indexedRate = $row->total > 0 ? round(($row->indexed / $row->total) * 100, 1) : 0;

        // 按站点统计
        $bySite = DB::table('article_tracking')
            ->join('target_sites', 'article_tracking.site_id', '=', 'target_sites.id')
            ->select('target_sites.name as site_name',
                DB::raw('COUNT(*) as total'),
                DB::raw('COUNT(*) FILTER (WHERE is_indexed = true) as indexed'),
                DB::raw('ROUND(COUNT(*) FILTER (WHERE is_indexed = true)::numeric / COUNT(*) * 100, 1) as rate')
            )
            ->groupBy('target_sites.name')
            ->orderBy('rate', 'desc')
            ->get();

        // 按状态统计
        $byStatus = DB::table('article_tracking')
            ->select('check_status', DB::raw('COUNT(*) as count'))
            ->groupBy('check_status')
            ->get();

        return [
            'indexed_rate' => $indexedRate,
            'total_tracked' => $row->total ?? 0,
            'indexed_count' => $row->indexed ?? 0,
            'not_indexed_count' => $row->not_indexed ?? 0,
            'avg_check_age_hours' => $row->avg_check_age_hours ?? 0,
            'by_site' => $bySite,
            'by_status' => $byStatus,
        ];
    }

    // ==================== 私有方法 ====================

    /**
     * 生成文章 URL。
     */
    private function generateArticleUrl(Article $article, TargetSite $site): string
    {
        $baseUrl = rtrim($site->url, '/');
        return "{$baseUrl}/article/{$article->slug}";
    }

    /**
     * 验证文章是否被收录。
     *
     * 实际实现应使用搜索引擎 API：
     * - 百度：https://ziyuan.baidu.com/site/
     * - 谷歌：https://developers.google.com/search/apis/indexing-api
     */
    private function verifyIndexStatus(string $url, string $searchEngine): bool
    {
        // 临时实现：模拟检查（生产环境需替换为真实 API 调用）
        // 这里返回 false 表示未收录，实际应调用搜索引擎 API
        
        // 示例：百度 site 查询
        // $response = Http::get("https://www.baidu.com/s?wd=site:{$url}");
        
        return false; // 占位：需实现真实 API 集成
    }

    /**
     * 搜索关键词排名。
     */
    private function searchKeyword(string $keyword, string $searchEngine, int $maxPages): array
    {
        // 占位实现：需使用搜索引擎 API 或第三方排名工具
        return ['rank' => null, 'url' => null, 'title' => null];
    }
}
