<?php
/**
 * GEO+AI内容生成系统 - 轻量调度器
 * 职责：补齐任务入队、恢复卡死 job、自动发布审核通过文章、自动恢复暂停任务
 */

define('FEISHU_TREASURE', true);

$projectRoot = dirname(__DIR__);
chdir($projectRoot);

require_once $projectRoot . '/includes/config.php';
require_once $projectRoot . '/includes/database_admin.php';
require_once $projectRoot . '/includes/job_queue_service.php';
require_once $projectRoot . '/includes/Parsedown.php';

set_time_limit(120);

$startTime = microtime(true);
$logMessages = [];

function log_message($message) {
    global $logMessages;
    $timestamp = date('Y-m-d H:i:s');
    $logMessages[] = "[{$timestamp}] {$message}";
    echo "[{$timestamp}] {$message}\n";
}

try {
    log_message('轻量调度器开始执行');

    $queueService = new JobQueueService($db);
    $recoveredCount = $queueService->recoverStaleJobs();
    if ($recoveredCount > 0) {
        log_message("恢复 {$recoveredCount} 个卡住的 job");
    }

    $stmt = $db->query("
        SELECT
            t.id,
            t.name,
            t.publish_interval,
            t.draft_limit,
            t.next_run_at,
            COALESCE(t.schedule_enabled, 1) AS schedule_enabled,
            (
                SELECT COUNT(*)
                FROM articles a
                WHERE a.task_id = t.id
                  AND a.status = 'draft'
                  AND a.deleted_at IS NULL
            ) AS draft_count
        FROM tasks t
        WHERE t.status = 'active'
        ORDER BY t.updated_at ASC, t.id ASC
    ");
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    log_message('扫描到 ' . count($tasks) . ' 个活跃任务');

    $queuedCount = 0;
    $skippedCount = 0;

    foreach ($tasks as $task) {
        if ((int) $task['schedule_enabled'] !== 1) {
            $skippedCount++;
            continue;
        }

        if ((int) $task['draft_count'] >= (int) $task['draft_limit']) {
            log_message("任务 {$task['name']} 草稿已满 ({$task['draft_count']}/{$task['draft_limit']})，跳过入队");
            $skippedCount++;
            continue;
        }

        if (empty($task['next_run_at'])) {
            $queueService->initializeTaskSchedule((int) $task['id']);
            log_message("任务 {$task['name']} 初始化 next_run_at，等待下一轮调度");
            $skippedCount++;
            continue;
        }

        if (strtotime($task['next_run_at']) > time()) {
            $skippedCount++;
            continue;
        }

        if ($queueService->hasPendingOrRunningJob((int) $task['id'])) {
            log_message("任务 {$task['name']} 已有待执行 job，跳过重复入队");
            $skippedCount++;
            continue;
        }

        $jobId = $queueService->enqueueTaskJob((int) $task['id']);
        if ($jobId === null) {
            $skippedCount++;
            continue;
        }

        $nextRunAt = date('Y-m-d H:i:s', time() + max(60, (int) $task['publish_interval']));
        $update = $db->prepare("
            UPDATE tasks
            SET next_run_at = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $update->execute([$nextRunAt, $task['id']]);

        $queuedCount++;
        log_message("任务 {$task['name']} 已入队 job #{$jobId}，下次执行时间 {$nextRunAt}");
    }

    cleanupTaskSchedules();
    resetDailyAIUsage();
    autoPublishApprovedArticles();
    
    $pushResults = autoPushToSite(10);
    foreach ($pushResults as $r) {
        log_message($r);
    }
    
    require_once $projectRoot . '/bin/auto_refill_titles.php';
    $refillResults = autoRefillTitleLibraries(5, 20);
    foreach ($refillResults as $r) {
        if ($r['action'] === 'refilled') {
            log_message("标题库自动补充: {$r['name']} - {$r['message']}");
        } elseif ($r['action'] === 'error') {
            log_message("标题库补充失败: {$r['name']} - {$r['message']}");
        }
    }
    
    autoResumeTasks();

    $executionTime = round(microtime(true) - $startTime, 2);
    log_message("轻量调度器执行完成，入队 {$queuedCount} 个任务，跳过 {$skippedCount} 个任务");
    saveExecutionLog($queuedCount, $skippedCount, $recoveredCount, $executionTime);
} catch (Throwable $e) {
    log_message('轻量调度器异常: ' . $e->getMessage());
    $stmt = $db->prepare("INSERT INTO system_logs (type, message, data) VALUES (?, ?, ?)");
    $stmt->execute([
        'error',
        '轻量调度器执行异常: ' . $e->getMessage(),
        json_encode([
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ], JSON_UNESCAPED_UNICODE)
    ]);
    exit(1);
}

function cleanupTaskSchedules() {
    global $db;
    $stmt = $db->prepare("
        DELETE FROM task_schedules
        WHERE created_at < " . db_now_minus_seconds_sql(7 * 24 * 60 * 60) . "
    ");
    $stmt->execute();
}

function resetDailyAIUsage() {
    global $db;
    $today = date('Y-m-d');
    $stmt = $db->prepare("
        UPDATE ai_models
        SET used_today = 0, updated_at = CURRENT_TIMESTAMP
        WHERE DATE(updated_at) < ?
          AND used_today > 0
    ");
    $stmt->execute([$today]);
}

function saveExecutionLog($queuedCount, $skippedCount, $recoveredCount, $executionTime) {
    global $db, $logMessages;
    $logData = [
        'queued_count' => $queuedCount,
        'skipped_count' => $skippedCount,
        'recovered_count' => $recoveredCount,
        'execution_time' => $executionTime,
        'messages' => $logMessages
    ];
    $stmt = $db->prepare("
        INSERT INTO system_logs (type, message, data)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([
        'cron',
        "轻量调度器执行完成: 入队 {$queuedCount} 个任务，跳过 {$skippedCount} 个任务",
        json_encode($logData, JSON_UNESCAPED_UNICODE)
    ]);
    $logFile = __DIR__ . '/logs/cron_' . date('Y-m-d') . '.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    file_put_contents($logFile, implode("\n", $logMessages) . "\n", FILE_APPEND | LOCK_EX);
}

function autoPublishApprovedArticles() {
    global $db;
    $stmt = $db->prepare("
        SELECT a.*, t.publish_interval
        FROM articles a
        JOIN tasks t ON a.task_id = t.id
        WHERE a.review_status = 'approved'
          AND a.status = 'draft'
          AND a.deleted_at IS NULL
          AND t.need_review = 0
    ");
    $stmt->execute();
    $articlesToPublish = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $publishedCount = 0;
    foreach ($articlesToPublish as $article) {
        $createdTime = strtotime($article['created_at']);
        if ((time() - $createdTime) < (int) $article['publish_interval']) {
            continue;
        }
        $publish = $db->prepare("
            UPDATE articles
            SET status = 'published',
                published_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $publish->execute([$article['id']]);
        $taskUpdate = $db->prepare("
            UPDATE tasks
            SET published_count = published_count + 1
            WHERE id = ?
        ");
        $taskUpdate->execute([$article['task_id']]);
        $publishedCount++;
        log_message("自动发布文章: {$article['title']} (ID: {$article['id']})");
    }
    if ($publishedCount > 0) {
        log_message("自动发布了 {$publishedCount} 篇文章");
    }
}

// 清理emoji字符（目标网站不支持utf8mb4）
// Markdown转HTML（同步到网站时转换）
require_once __DIR__ . "/../includes/Parsedown.php";


// 清理AI输出中的思考过程和模板残留（同步时二次清理）
function cleanAIOutputForSync($content) {
    if (empty($content)) return $content;
    
    // 清理思考过程标签
    $content = preg_replace('/\<think\>.*\<\/think\>/s', '', $content);
    $content = preg_replace('/\x{03}.*?\x{03}/us', '', $content);
    
    // 清理英文思考过程段落
    $content = preg_replace('/^The user.*$/im', '', $content);
    $content = preg_replace('/^Let me.*$/im', '', $content);
    $content = preg_replace('/^I need.*$/im', '', $content);
    $content = preg_replace('/^Here is.*$/im', '', $content);
    
    // 清理中文思考过程段落
    $content = preg_replace('/^我需要.*$/im', '', $content);
    $content = preg_replace('/^让我.*$/im', '', $content);
    $content = preg_replace('/^首先.*$/im', '', $content);
    
    // 清理提示词模板残留
    $content = preg_replace('/##\s*[一二三四五六七八九十]+、\s*\[.*?此处.*?\]/u', '', $content);
    
    // 找到第一个有效的 ## 标题
    if (preg_match('/(##\s+.+)/s', $content, $m, PREG_OFFSET_CAPTURE)) {
        $content = substr($content, $m[0][1]);
    }
    
    // 清理多余空行
    $content = preg_replace('/\n{3,}/', "\n\n", $content);
    
    return trim($content);
}

function convertMarkdownToHtml($content) {
    if (empty($content)) return $content;
    $parsedown = new Parsedown();
    $parsedown->setSafeMode(false);
    $parsedown->setBreaksEnabled(true);
    $html = $parsedown->text($content);
    $baseUrl = "https://img.chengenyiliao.cn";
    $html = preg_replace('#src="(/uploads/[^"]+)"#i', 'src="' . $baseUrl . '$1"', $html);
    $html = preg_replace('#src="(uploads/[^"]+)"#i', 'src="' . $baseUrl . '/$1"', $html);
    return $html;
}

function cleanEmojiForSync($text) {
    if (empty($text)) return $text;
    $clean = '';
    $i = 0;
    $len = strlen($text);
    while ($i < $len) {
        $b = ord($text[$i]);
        if ($b >= 0xF0 && $b <= 0xF7 && $i + 3 < $len) {
            // 跳过4字节emoji (F0-F7开头)
            $i += 4;
        } else {
            $clean .= $text[$i];
            $i++;
        }
    }
    return $clean;
}
function autoPushToSite($limit = 10) {
    global $db;
    $results = [];
    
    // 从数据库读取站点配置
    $stmt = $db->query("
        SELECT id, name, url, sync_token, default_category_id, default_keywords, status
        FROM target_sites
        WHERE status = 'active'
    ");
    $targetSites = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($targetSites)) {
        return ['未找到活跃的目标站点'];
    }
    
    // 创建同步日志表
    $db->exec("CREATE TABLE IF NOT EXISTS article_sync_log (
        id SERIAL PRIMARY KEY,
        article_id INTEGER NOT NULL,
        target VARCHAR(50) NOT NULL DEFAULT 'eyoucms',
        target_id VARCHAR(100),
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        error_message TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(article_id, target)
    )");
    
    // 遍历每个目标站点，查找关联任务的文章
    foreach ($targetSites as $site) {
        $siteId = $site['id'];
        $targetName = 'eyoucms_site_' . $siteId;
        
        // 查找关联此站点的任务
        $stmt = $db->prepare("SELECT id FROM tasks WHERE site_id = ?");
        $stmt->execute([$siteId]);
        $taskIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($taskIds)) {
            continue;
        }
        
        $taskIdsStr = implode(',', $taskIds);
        $stmt = $db->prepare("
            SELECT a.id, a.title, a.content, a.meta_description, a.keywords, a.task_id
            FROM articles a
            LEFT JOIN article_sync_log s ON s.article_id = a.id AND s.target = ?
            WHERE a.status = 'published'
              AND a.deleted_at IS NULL
              AND a.task_id IN ($taskIdsStr)
              AND s.id IS NULL
            ORDER BY a.published_at ASC
            LIMIT {$limit}
        ");
        $stmt->execute([$targetName]);
        $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($articles)) {
            continue;
        }
        
        $results[] = "[{$site['name']}] 发现 " . count($articles) . " 篇待推送文章 (任务: {$taskIdsStr})";
        
        foreach ($articles as $article) {
            $postData = json_encode([
                'title' => cleanEmojiForSync($article['title']),
                'content' => convertMarkdownToHtml(cleanAIOutputForSync(cleanEmojiForSync($article['content']))),
                'description' => cleanEmojiForSync($article['meta_description'] ?? ''),
                'category_id' => $site['default_category_id'],
                'seo_keywords' => cleanEmojiForSync($article['keywords'] ?? $site['default_keywords']),
                'seo_description' => cleanEmojiForSync($article['meta_description'] ?? ''),
                'seo_title' => $article['title'],
            ]);
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $site['url'],
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postData,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Sync-Token: ' . $site['sync_token'],
                ],
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                $results[] = "推送失败 [{$article['id']}]: CURL错误 - {$error}";
                continue;
            }
            
            $result = json_decode($response, true);
            if ($result && ($result['code'] ?? -1) === 0) {
                $remoteId = $result['data']['article_id'] ?? '?';
                $results[] = "推送成功 [{$article['id']}] → {$site['name']} ID={$remoteId}: {$article['title']}";
                $stmt2 = $db->prepare("
                    INSERT INTO article_sync_log (article_id, target, target_id, status, created_at)
                    VALUES (?, ?, ?, 'success', CURRENT_TIMESTAMP)
                    ON CONFLICT (article_id, target) DO NOTHING
                ");
                $stmt2->execute([$article['id'], $targetName, $remoteId]);
            } else {
                $errMsg = $result['message'] ?? "HTTP {$httpCode}";
                $results[] = "推送失败 [{$article['id']}]: {$errMsg}";
                $stmt2 = $db->prepare("
                    INSERT INTO article_sync_log (article_id, target, status, error_message, created_at)
                    VALUES (?, ?, 'failed', ?, CURRENT_TIMESTAMP)
                    ON CONFLICT (article_id, target) DO NOTHING
                ");
                $stmt2->execute([$article['id'], $targetName, $errMsg]);
            }
            sleep(1);
        }
    }
    return $results;
}
function autoResumeTasks() {
    global $db;
    require_once dirname(__DIR__) . '/includes/task_lifecycle_service.php';
    $lifecycleService = new TaskLifecycleService($db);
    $stmt = $db->query("
        SELECT id, name, stopped_at, auto_resume_delay_minutes
        FROM tasks
        WHERE status = 'paused'
          AND auto_resume_enabled = 1
          AND stopped_at IS NOT NULL
          AND stopped_at + (auto_resume_delay_minutes || ' minutes')::interval <= CURRENT_TIMESTAMP
    ");
    $tasksToResume = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($tasksToResume)) {
        return;
    }
    foreach ($tasksToResume as $task) {
        try {
            $result = $lifecycleService->startTask((int)$task['id'], true);
            if (isset($result['success']) && $result['success']) {
                log_message("自动恢复任务: {$task['name']} (ID: {$task['id']}, 延迟: {$task['auto_resume_delay_minutes']}分钟)");
                $logStmt = $db->prepare("
                    INSERT INTO system_logs (type, message, data)
                    VALUES ('auto_resume', ?, ?)
                ");
                $logStmt->execute([
                    "任务自动恢复: {$task['name']}",
                    json_encode([
                        'task_id' => $task['id'],
                        'task_name' => $task['name'],
                        'delay_minutes' => $task['auto_resume_delay_minutes'],
                        'stopped_at' => $task['stopped_at'],
                        'resumed_at' => date('Y-m-d H:i:s')
                    ], JSON_UNESCAPED_UNICODE)
                ]);
            }
        } catch (Throwable $e) {
            log_message("自动恢复任务失败: {$task['name']} - " . $e->getMessage());
        }
    }
}