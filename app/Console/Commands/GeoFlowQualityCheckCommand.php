<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GeoFlowQualityCheckCommand extends Command
{
    protected $signature = 'geoflow:quality-check
                            {--task= : 指定任务ID（多个用逗号分隔）}
                            {--days=1 : 检查最近N天的文章}
                            {--threshold=20 : 失败率告警阈值百分比}
                            {--engine=all : 检查引擎 all|baidu|google}';

    protected $description = 'GEOFlow 文章质量每日检查：检测中文混入、内容完整性等';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $threshold = (int) $this->option('threshold');
        $engine = (string) $this->option('engine');
        $taskIds = $this->parseTaskIds();

        $startDate = now()->subDays($days)->startOfDay();

        $this->info("=== GEOFlow 文章质量检查 ===");
        $this->info("检查时间: ".now()->format('Y-m-d H:i'));
        $this->info("检查范围: 最近 {$days} 天");
        if (! empty($taskIds)) {
            $this->info("指定任务: ".implode(', ', $taskIds));
        }

        // 获取待检查的文章
        $query = Article::query()
            ->select('id', 'title', 'content', 'task_id', 'status', 'created_at')
            ->where('status', 'published')
            ->where('created_at', '>=', $startDate);

        if (! empty($taskIds)) {
            $query->whereIn('task_id', $taskIds);
        }

        $articles = $query->orderBy('created_at', 'desc')->get();

        if ($articles->isEmpty()) {
            $this->warn("没有需要检查的文章。");
            return 0;
        }

        $this->table(['ID', '任务', '标题', '状态'], $articles->map(fn ($a) => [
            $a->id,
            $a->task?->name ?? '-',
            mb_substr($a->title, 0, 40),
            $a->status,
        ]));

        $results = $this->checkArticles($articles, $engine);
        $this->printReport($results, $threshold);

        return 0;
    }

    /**
     * @return list<int>
     */
    protected function parseTaskIds(): array
    {
        $taskOption = (string) $this->option('task');
        if ($taskOption === '') {
            return [];
        }

        return array_map('intval', array_filter(explode(',', $taskOption)));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Article>  $articles
     * @return array{total:int, passed:int, failed:int, details:array}
     */
    protected function checkArticles($articles, string $engine): array
    {
        $details = [];
        $passed = 0;
        $failed = 0;

        foreach ($articles as $article) {
            $articleChecks = [];
            $hasFailure = false;

            // 检查1：语言一致性（英文任务不应含中文）
            if ($engine === 'all' || $engine === 'baidu') {
                $langResult = $this->checkLanguageConsistency($article);
                $articleChecks['language'] = $langResult;
                if ($langResult['status'] === 'fail') {
                    $hasFailure = true;
                    $this->recordQualityCheck($article, 'language', 'fail', $langResult['detail']);
                } elseif ($langResult['status'] === 'warn') {
                    $this->recordQualityCheck($article, 'language', 'warn', $langResult['detail']);
                }
            }

            // 检查2：内容完整性（不应有空白内容或截断标记）
            $contentResult = $this->checkContentIntegrity($article);
            $articleChecks['content_integrity'] = $contentResult;
            if ($contentResult['status'] === 'fail') {
                $hasFailure = true;
                $this->recordQualityCheck($article, 'content_integrity', 'fail', $contentResult['detail']);
            }

            // 检查3：标题与内容一致性（标题不应出现在正文开头重复）
            $titleResult = $this->checkTitleConsistency($article);
            $articleChecks['title_consistency'] = $titleResult;
            if ($titleResult['status'] === 'fail') {
                $hasFailure = true;
                $this->recordQualityCheck($article, 'title_consistency', 'fail', $titleResult['detail']);
            }

            // 检查4：TDK完整性
            $tdkResult = $this->checkTdkCompleteness($article);
            $articleChecks['tdk'] = $tdkResult;
            if ($tdkResult['status'] === 'fail') {
                $hasFailure = true;
                $this->recordQualityCheck($article, 'tdk', 'fail', $tdkResult['detail']);
            }

            if ($hasFailure) {
                $failed++;
                $details[$article->id] = [
                    'title' => $article->title,
                    'task_id' => $article->task_id,
                    'task_name' => $article->task?->name ?? '-',
                    'checks' => $articleChecks,
                ];
            } else {
                $passed++;
            }
        }

        return [
            'total' => $articles->count(),
            'passed' => $passed,
            'failed' => $failed,
            'details' => $details,
        ];
    }

    /**
     * @return array{status:string,detail:string}
     */
    protected function checkLanguageConsistency(Article $article): array
    {
        $task = $article->task;
        if (! $task) {
            return ['status' => 'skip', 'detail' => '任务不存在'];
        }

        // 仅对明确标记为英文的任务（90817 系列）进行语言检查
        $taskName = (string) $task->name;
        $isEnglishTask = str_contains($taskName, '90817');

        // 非英文任务跳过语言一致性检查
        if (! $isEnglishTask) {
            return ['status' => 'skip', 'detail' => '非英文任务，跳过语言检查'];
        }

        $content = (string) $article->content;
        $chineseCount = preg_match_all('/[\x{4e00}-\x{9fff}]/u', $content);

        if ($chineseCount > 0) {
            return [
                'status' => 'fail',
                'detail' => "英文任务检测到 {$chineseCount} 个中文字符。标题: ".mb_substr($article->title, 0, 50),
            ];
        }

        return ['status' => 'pass', 'detail' => '语言一致性正常'];
    }

    /**
     * @return array{status:string,detail:string}
     */
    protected function checkContentIntegrity(Article $article): array
    {
        $content = trim((string) $article->content);

        if ($content === '') {
            return ['status' => 'fail', 'detail' => '文章内容为空'];
        }

        if (strlen($content) < 100) {
            return ['status' => 'fail', 'detail' => '文章内容过短（<100字符）'];
        }

        // 检查截断标记
        if (str_contains($content, '[TRUNCATED]')
            || str_contains($content, '[内容被截断]')
            || str_contains($content, '...') && strlen($content) < 500) {
            return ['status' => 'fail', 'detail' => '文章内容可能被截断'];
        }

        // 检查是否有完整的HTML结构（如果有HTML内容）
        if (str_contains($content, '<') && str_contains($content, '>')) {
            $openTags = preg_match_all('/<([a-z]+)[\s>]/i', $content, $openMatches);
            $closeTags = preg_match_all('/<\/([a-z]+)>/i', $content, $closeMatches);
            if ($openTags > 0 && $openTags !== $closeTags) {
                return ['status' => 'warn', 'detail' => "HTML标签可能不匹配（开标签{$openTags}个，闭标签{$closeTags}个）"];
            }
        }

        return ['status' => 'pass', 'detail' => '内容完整性正常'];
    }

    /**
     * @return array{status:string,detail:string}
     */
    protected function checkTitleConsistency(Article $article): array
    {
        $title = (string) $article->title;
        $content = (string) $article->content;

        // 检查标题是否重复出现在正文开头
        $contentWithoutTitle = preg_replace('/^#{1,6}\s*' . preg_quote($title, '/') . '\s*\n/iu', '', $content);
        $contentWithoutTitle = preg_replace('/^' . preg_quote($title, '/') . '\s*\n/iu', '', $contentWithoutTitle);

        if ($contentWithoutTitle === $content && strlen($title) > 5) {
            // 标题未在正文开头重复，正常
            return ['status' => 'pass', 'detail' => '标题未重复出现在正文'];
        }

        // 检查正文开头是否有与标题高度相似的内容
        $first100 = mb_substr($contentWithoutTitle, 0, 100);
        if (mb_strpos($first100, $title) !== false) {
            return [
                'status' => 'warn',
                'detail' => '正文开头可能重复了标题内容',
            ];
        }

        return ['status' => 'pass', 'detail' => '标题一致性正常'];
    }

    /**
     * @return array{status:string,detail:string}
     */
    protected function checkTdkCompleteness(Article $article): array
    {
        $issues = [];

        if (trim((string) $article->title) === '') {
            $issues[] = '标题为空';
        }

        if (trim((string) ($article->keywords ?? '')) === '') {
            $issues[] = '关键词为空';
        }

        if (trim((string) ($article->meta_description ?? '')) === '') {
            $issues[] = 'Meta描述为空';
        }

        if ($issues === []) {
            return ['status' => 'pass', 'detail' => 'TDK完整'];
        }

        return ['status' => 'fail', 'detail' => 'TDK缺失: '.implode(', ', $issues)];
    }

    protected function recordQualityCheck(Article $article, string $checkType, string $result, string $detail): void
    {
        DB::table('article_quality_check')->insertOrIgnore([
            'article_id' => $article->id,
            'check_type' => $checkType,
            'check_result' => $result,
            'detail' => $detail,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array{total:int,passed:int,failed:int,details:array}  $results
     */
    protected function printReport(array $results, int $threshold): void
    {
        $total = $results['total'];
        $passed = $results['passed'];
        $failed = $results['failed'];
        $rate = $total > 0 ? ($passed / $total * 100) : 0;

        $this->newLine();
        $this->info("=== 质量检查报告 ===");
        $this->table(['指标', '数值'], [
            ['检查文章数', $total],
            ['通过', $passed],
            ['失败', $failed],
            ['通过率', number_format($rate, 1).'%'],
        ]);

        if (! empty($results['details'])) {
            $this->newLine();
            $this->warn("⚠️ 失败文章详情:");
            foreach ($results['details'] as $aid => $detail) {
                $rows = [];
                foreach ($detail['checks'] as $checkName => $checkResult) {
                    if ($checkResult['status'] !== 'skip') {
                        $rows[] = [$checkName, $checkResult['status'], $checkResult['detail']];
                    }
                }
                if (! empty($rows)) {
                    $this->table(
                        ['检查项', '结果', '详情'],
                        $rows
                    );
                }
            }
        }

        if ($total > 0 && ($failed / $total * 100) > $threshold) {
            $this->newLine();
            $this->error("🔴 告警: 失败率 ".number_format($failed / $total * 100, 1)."% 超过阈值 {$threshold}%!");
        }
    }
}
