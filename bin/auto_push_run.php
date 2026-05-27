#!/usr/bin/env php
<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$startTime = microtime(true);
$pushedCount = 0;
$failedCount = 0;
$pushedArticles = [];

function cleanEmoji($text) {
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

function cleanAI($content) {
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

require_once '/var/www/html/vendor/erusev/parsedown/Parsedown.php';

function mdToHtml($content) {
    if (empty($content)) return $content;
    $p = new Parsedown();
    $p->setSafeMode(false);
    $p->setBreaksEnabled(true);
    $html = $p->text($content);
    $base = "https://img.chengenyiliao.cn";
    $html = preg_replace('#src="(/uploads/[^"]+)"#i', 'src="' . $base . '$1"', $html);
    $html = preg_replace('#src="(uploads/[^"]+)"#i', 'src="' . $base . '/$1"', $html);
    return $html;
}

$limit = 10;
$sites = DB::table('target_sites')->where('status', 'active')->get();

foreach ($sites as $site) {
    $siteId = $site->id;
    $targetName = 'eyoucms_site_' . $siteId;
    $taskIds = DB::table('tasks')->where('site_id', $siteId)->pluck('id')->toArray();
    if (empty($taskIds)) continue;

    $taskIdsStr = implode(',', $taskIds);
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

    foreach ($articles as $article) {
        $postData = json_encode([
            'title' => cleanEmoji($article->title),
            'content' => mdToHtml(cleanAI(cleanEmoji($article->content))),
            'description' => cleanEmoji($article->meta_description ?? ''),
            'category_id' => $site->default_category_id,
            'seo_keywords' => cleanEmoji($article->keywords ?? $site->default_keywords),
            'seo_description' => cleanEmoji($article->meta_description ?? ''),
            'seo_title' => $article->title,
        ], JSON_UNESCAPED_UNICODE);

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
            $failedCount++;
            echo "FAIL article:{$article->id} site:{$site->name} error:CURL - {$error}\n";
            continue;
        }

        $result = json_decode($response, true);
        if ($result && ($result['code'] ?? -1) === 0) {
            $remoteId = $result['data']['article_id'] ?? '?';
            $pushedCount++;
            $pushedArticles[] = ['id' => $article->id, 'title' => $article->title, 'site' => $site->name, 'remote_id' => $remoteId];
            echo "OK article:{$article->id} remote:{$remoteId} site:{$site->name} title:{$article->title}\n";

            DB::insert("
                INSERT INTO article_sync_log (article_id, target, target_id, status, created_at)
                VALUES (?, ?, ?, 'success', CURRENT_TIMESTAMP)
                ON CONFLICT (article_id, target) DO NOTHING
            ", [$article->id, $targetName, $remoteId]);
        } else {
            $errMsg = $result['message'] ?? "HTTP {$httpCode}";
            $failedCount++;
            echo "FAIL article:{$article->id} site:{$site->name} error:{$errMsg}\n";

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
echo "\n===== SUMMARY =====\n";
echo "Pushed: {$pushedCount}\n";
echo "Failed: {$failedCount}\n";
echo "Time: {$elapsed}s\n";
