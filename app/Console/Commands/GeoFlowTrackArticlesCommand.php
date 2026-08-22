<?php

namespace App\Console\Commands;

use App\Services\GeoFlow\ArticleTrackingService;
use Illuminate\Console\Command;

class GeoFlowTrackArticlesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'geoflow:track-articles 
                            {--task= : 指定任务 ID，省略则检查所有任务}
                            {--engine=baidu : 搜索引擎（baidu/google/bing）}
                            {--report : 输出汇总报告}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '检查文章收录状态并生成追踪报告';

    protected ArticleTrackingService $trackingService;

    /**
     * Execute the console command.
     */
    public function handle(ArticleTrackingService $trackingService): int
    {
        $this->trackingService = $trackingService;

        $taskId = $this->option('task');
        $engine = $this->option('engine');
        $showReport = $this->option('report');

        if ($showReport) {
            $report = $trackingService->getTrackingReport($taskId ? (int) $taskId : null);
            $this->displayReport($report);
            return Command::SUCCESS;
        }

        if ($taskId) {
            $this->info("开始检查任务 {$taskId} 的文章收录状态...");
            $stats = $trackingService->checkTaskArticles((int) $taskId, $engine);
            $this->displayStats($stats);
        } else {
            // 检查所有活跃任务
            $tasks = \App\Models\Task::query()
                ->where('status', 'active')
                ->where('schedule_enabled', 1)
                ->get();

            $this->info("开始检查 {$tasks->count()} 个活跃任务的文章收录状态...");

            foreach ($tasks as $task) {
                $this->info("  检查任务: {$task->name} (ID:{$task->id})");
                $stats = $trackingService->checkTaskArticles($task->id, $engine);
                $this->displayStats($stats);
            }
        }

        // 显示汇总报告
        $report = $trackingService->getTrackingReport();
        $this->newLine();
        $this->info('=== 汇总报告 ===');
        $this->displayReport($report);

        return Command::SUCCESS;
    }

    /**
     * 显示统计信息。
     */
    private function displayStats(array $stats): void
    {
        $this->table(
            ['指标', '数量'],
            [
                ['总检查数', $stats['total']],
                ['已收录', $stats['indexed']],
                ['未收录', $stats['pending']],
                ['检查失败', $stats['failed']],
            ]
        );
    }

    /**
     * 显示汇总报告。
     */
    private function displayReport(array $report): void
    {
        $this->table(
            ['指标', '值'],
            [
                ['收录率', "{$report['indexed_rate']}%"],
                ['追踪文章数', $report['total_tracked']],
                ['已收录', $report['indexed_count']],
                ['未收录', $report['not_indexed_count']],
                ['平均检查间隔', "{$report['avg_check_age_hours']} 小时"],
            ]
        );

        if ($report['by_site']->isNotEmpty()) {
            $this->newLine();
            $this->info('按站点统计:');
            $this->table(
                ['站点', '总数', '已收录', '收录率'],
                $report['by_site']->map(fn($s) => [$s->site_name, $s->total, $s->indexed, "{$s->rate}%"])->toArray()
            );
        }
    }
}
