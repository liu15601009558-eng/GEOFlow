<?php

/**
 * Artisan 自定义命令注册（闭包命令或后续类命令）。
 */

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Horizon 监控快照：用于沉淀队列吞吐、等待等时序指标。
 */


/**
 * GeoFlow 任务调度：每分钟扫描一次可执行任务并入队（对齐 bak cron 逻辑）。
 * 保留子进程输出并记录失败，便于定位历史 exit 255 问题。
 */
Schedule::command('geoflow:schedule-tasks')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->appendOutputTo(storage_path('logs/schedule-tasks.log'))
    ->onFailure(fn () => \Illuminate\Support\Facades\Log::error('geoflow.schedule_tasks_failed'));

/**
 * 文章收录追踪：功能尚未实现（verifyIndexStatus 仍为占位实现），
 * 且依赖的 TargetSite 模型曾缺失，先停用调度避免每小时报错。
 * 待接入真实百度收录 API 后恢复。
 */
// Schedule::command('geoflow:track-articles --report')->dailyAt('03:00');
// Schedule::command('geoflow:track-articles --engine=baidu')->hourly();

/**
 * 文章质量检查：每天凌晨 4 点检查所有已发布文章的质量。
 */
Schedule::command('geoflow:quality-check --days=1 --threshold=20')->dailyAt('04:00');

/**
 * GEOFlow 文章同步到 EyouCMS：每 10 分钟推送一次已发布文章。
 * 通过 sync_article.php 写入远端数据库，远端自动分类+百度推送。
 */
Schedule::exec('php ' . base_path('bin/auto_push_standalone.php'))
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/cron-sync.log'));
