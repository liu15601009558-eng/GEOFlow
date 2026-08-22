<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\GeoFlow\ArticleTrackingService;
use App\Models\Article;
use App\Models\TargetSite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrackingController extends Controller
{
    protected ArticleTrackingService $trackingService;

    public function __construct(ArticleTrackingService $trackingService)
    {
        $this->trackingService = $trackingService;
    }

    /**
     * 显示追踪总览页面。
     */
    public function index(Request $request)
    {
        $taskId = $request->input('task_id');
        $report = $this->trackingService->getTrackingReport($taskId);

        // 获取任务列表供筛选
        $tasks = \App\Models\Task::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        // 获取最近追踪记录
        $recentChecks = DB::table('article_tracking')
            ->join('articles', 'article_tracking.article_id', '=', 'articles.id')
            ->join('target_sites', 'article_tracking.site_id', '=', 'target_sites.id')
            ->select(
                'article_tracking.*',
                'articles.title as article_title',
                'articles.slug as article_slug',
                'target_sites.name as site_name',
                'target_sites.url as site_url'
            )
            ->orderBy('article_tracking.last_checked_at', 'desc')
            ->limit(50)
            ->get();

        return view('admin.tracking.index', [
            'report' => $report,
            'tasks' => $tasks,
            'selectedTaskId' => $taskId,
            'recentChecks' => $recentChecks,
        ]);
    }

    /**
     * 手动触发收录检查。
     */
    public function checkNow(Request $request)
    {
        $request->validate([
            'task_id' => 'nullable|integer|exists:tasks,id',
            'engine' => 'in:baidu,google,bing',
        ]);

        $taskId = $request->input('task_id');
        $engine = $request->input('engine', 'baidu');

        if ($taskId) {
            $stats = $this->trackingService->checkTaskArticles((int) $taskId, $engine);
        } else {
            $tasks = \App\Models\Task::query()
                ->where('status', 'active')
                ->where('schedule_enabled', 1)
                ->get();

            $stats = ['total' => 0, 'indexed' => 0, 'pending' => 0, 'failed' => 0];
            foreach ($tasks as $task) {
                $taskStats = $this->trackingService->checkTaskArticles($task->id, $engine);
                $stats['total'] += $taskStats['total'];
                $stats['indexed'] += $taskStats['indexed'];
                $stats['pending'] += $taskStats['pending'];
                $stats['failed'] += $taskStats['failed'];
            }
        }

        return redirect()->back()
            ->with('success', "检查完成：共 {$stats['total']} 条，已收录 {$stats['indexed']} 条，待收录 {$stats['pending']} 条。");
    }

    /**
     * 文章追踪详情。
     */
    public function show(Article $article)
    {
        $tracking = DB::table('article_tracking')
            ->join('target_sites', 'article_tracking.site_id', '=', 'target_sites.id')
            ->select('article_tracking.*', 'target_sites.name as site_name', 'target_sites.url as site_url')
            ->where('article_tracking.article_id', $article->id)
            ->get();

        return view('admin.tracking.show', [
            'article' => $article,
            'tracking' => $tracking,
        ]);
    }

    /**
     * API: 获取追踪汇总数据。
     */
    public function apiSummary(Request $request)
    {
        $taskId = $request->input('task_id');
        $report = $this->trackingService->getTrackingReport($taskId ? (int) $taskId : null);

        return response()->json($report);
    }
}
