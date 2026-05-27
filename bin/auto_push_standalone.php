#!/usr/bin/env php
<?php
/**
 * GEOFlow auto_push standalone runner
 * Extracts the push logic from cron.php and runs it independently using Laravel's DB
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$startTime = microtime(true);
$results = [];
$pushedCount = 0;
$failedCount = 0;

// Clean emoji (4-byte UTF-8 chars)
function cleanEmojiForSync($text) {
    if (empty($text)) return $text;
    $clean = '';
    $i = 0;
    $len = strlen($text);
    while ($i < $len) {
        $b = ord($text[$i]);
        if ($b >= 0xF0 && $b <= 0xF7 && $i + 3 < $len) {
            $i += 4;
        } else {
            $clean .= $text[$i];
            $i++;
        }
    }
    return $clean;
}

// Clean AI output artifacts
function cleanAIOutputForSync($content) {
    if (empty($content)) return $content;
    $content = preg_replace('/\<think\>.*\<\/think\>/s', '', $content);
    $content = preg_replace('/\x{03}.*?\x{03}/us', '', $content);
    $content = preg_replace('/^The user.*$/im', '', $content);
    $content = preg_replace('/^Let me.*$/im', '', $content);
    $content = preg_replace('/^I need.*$/im', '', $content);
    $content = preg_replace('/^Here is.*$/im', '', $content);
    $content = preg_replace('/^我需要.*$/im', '', $content);
    $content = preg_replace('/^让我.*$/im', '', $content);
    $content = preg_replace('/^首先.*$/im', '', $content);
    $content = preg_replace('/##\s*[一二三四五六七八九十]+、\s*\[.*?此处.*?\]/u', '', $content);
    if (preg_match('/(##\s+.+)/s', $content, $m, PREG_OFFSET_CAPTURE)) {
        $content = substr($content, $m[0][1]);
    }
    $content = preg_replace('/\n{3,}/', "\n\n", $content);
    return trim($content);
}

// Convert markdown to HTML
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

// Use Laravel Str::markdown or fallback
if (!class_exists('Parsedown')) {
    class Parsedown {
        public function setSafeMode($v) {}
        public function setBreaksEnabled($v) {}
        public function text($text) {
            return Illuminate\Support\Str::markdown($text ?? '');
        }
    }
}

$limit = 10;

// Get active sites
$sites = DB::table('target_sites')->where('status', 'active')->get();

foreach ($sites as $site) {
    $siteId = $site->id;
    $targetName = 'eyoucms_site_' . $siteId;

    // Get tasks for this site
    $taskIds = DB::table('tasks')->where('site_id', $siteId)->pluck('id')->toArray();
    if (empty($taskIds)) continue;

    $taskIdsStr = implode(',', $taskIds);

    // Find published articles not yet synced
    $articles = DB::select("
        SELECT a.id, a.title, a.content, a.meta_description, a.keywords, a.task_id
        FROM articles a
        LEFT JOIN article_sync_log s ON s.article_id = a.id AND s.target = ?
        WHERE a.status = 'published'
        AND a.deleted_at IS NULL
        AND a.task_id IN ({$taskIdsStr})
        AND s.id IS NULL
        ORDER BY a.published_at ASC
        LIMIT {$limit}
    ", [$targetName]);

    if (empty($articles)) continue;

    $results[] = "[{$site->name}] 发现 " . count($articles) . " 篇待推送文章";

    foreach ($articles as $article) {
        $postData = json_encode([
            'title' => cleanEmojiForSync($article->title),
            'content' => convertMarkdownToHtml(cleanAIOutputForSync(cleanEmojiForSync($article->content))),
            'description' => cleanEmojiForSync($article->meta_description ?? ''),
            'category_id' => $site->default_category_id,
            'seo_keywords' => cleanEmojiForSync($article->keywords ?? $site->default_keywords),
            'seo_description' => cleanEmojiForSync($article->meta_description ?? ''),
            'seo_title' => $article->title,
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $site->url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Sync-Token: ' . $site->sync_token,
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $results[] = "推送失败 [{$article->id}]: CURL错误 - {$error}";
            $failedCount++;
            continue;
        }

        $result = json_decode($response, true);
        if ($result && ($result['code'] ?? -1) === 0) {
            $remoteId = $result['data']['article_id'] ?? '?';
            $results[] = "✓ 推送成功 [文章ID:{$article->id}] → {$site->name} (远程ID:{$remoteId}) - {$article->title}";
            $pushedCount++;

            DB::insert("
                INSERT INTO article_sync_log (article_id, target, target_id, status, created_at)
                VALUES (?, ?, ?, 'success', CURRENT_TIMESTAMP)
                ON CONFLICT (article_id, target) DO NOTHING
            ", [$article->id, $targetName, $remoteId]);
        } else {
            $errMsg = $result['message'] ?? "HTTP {$httpCode}";
            $results[] = "✗ 推送失败 [文章ID:{$article->id}]: {$errMsg}";
            $failedCount++;

            DB::insert("
                INSERT INTO article_sync_log (article_id, target, status, error_message, created_at)
                VALUES (?, ?, 'failed', ?, CURRENT_TIMESTAMP)
                ON CONFLICT (article_id, target) DO NOTHING
            ", [$article->id, $targetName, $errMsg]);
        }
        sleep(1);
    }
}

$elapsed = round(microtime(true) - $startTime, 1);
echo "===== GEOFlow 文章推送结果 =====\n";
echo "推送成功: {$pushedCount} 篇\n";
echo "推送失败: {$failedCount} 篇\n";
echo "耗时: {$elapsed}s\n";
echo "================================\n";
foreach ($results as $r) {
    echo $r . "\n";
}
if (empty($results)) {
    echo "无待推送文章\n";
}
