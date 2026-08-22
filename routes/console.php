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
 */
Schedule::command('geoflow:schedule-tasks')->everyMinute();

/**
 * 文章收录追踪：每天凌晨 3 点检查所有已发布文章的收录状态。
 */
Schedule::command('geoflow:track-articles --report')->dailyAt('03:00');

/**
 * 文章收录追踪：每小时检查新发布文章（发布后 24 小时内）。
 */
Schedule::command('geoflow:track-articles --engine=baidu')->hourly();

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
