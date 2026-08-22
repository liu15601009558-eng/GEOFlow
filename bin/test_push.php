<?php
/**
 * GEOFlow article push to EyouCMS (test run).
 * Pushes a small batch to verify everything works.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$limit = (int) ($argv[1] ?? 5);

echo "=== GEOFlow 文章推送测试 ===\n";
echo "限制: {$limit} 篇\n\n";

// Get active sites
$sites = DB::table('target_sites')->where('status', 'active')->get();

foreach ($sites as $site) {
    $siteId = $site->id;
    $targetName = 'eyoucms_site_' . $siteId;

    $taskIds = DB::table('tasks')->where('site_id', $siteId)->pluck('id')->toArray();
    if (empty($taskIds)) {
        echo "[{$site->name}] 无关联任务\n";
        continue;
    }

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
        LIMIT ?
    ", [$targetName, $limit]);

    echo "[{$site->name}] 待推送: " . count($articles) . " 篇 (任务: {$taskIdsStr})\n";

    if (empty($articles)) continue;

    foreach ($articles as $article) {
        // Convert content
        $content = $article->content;
        // Clean emoji
        $clean = '';
        $i = 0;
        $len = strlen($content);
        while ($i < $len) {
            $b = ord($content[$i]);
            if ($b >= 0xF0 && $b <= 0xF7 && $i + 3 < $len) {
                $i += 4;
            } else {
                $clean .= $content[$i];
                $i++;
            }
        }
        $content = $clean;

        // Markdown to HTML (use Laravel Str::markdown)
        $content = \Illuminate\Support\Str::markdown($content);

        $postData = json_encode([
            'title' => $article->title,
            'content' => $content,
            'description' => mb_substr(strip_tags($content), 0, 200),
            'category_id' => $site->default_category_id,
            'seo_keywords' => $article->keywords ?? $site->default_keywords,
            'seo_description' => $article->meta_description ?? '',
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
            echo "  ✗ [{$article->id}] CURL错误: {$error}\n";
            continue;
        }

        $result = json_decode($response, true);
        if ($result && ($result['code'] ?? -1) === 0) {
            $remoteId = $result['data']['article_id'] ?? '?';
            $catId = $result['data']['category_id'] ?? '?';
            echo "  ✓ [{$article->id}] → 远程ID={$remoteId} 栏目={$catId} {$article->title}\n";

            DB::insert("
                INSERT INTO article_sync_log (article_id, target, target_id, status, created_at)
                VALUES (?, ?, ?, 'success', CURRENT_TIMESTAMP)
                ON CONFLICT (article_id, target) DO NOTHING
            ", [$article->id, $targetName, $remoteId]);
        } else {
            $errMsg = $result['message'] ?? "HTTP {$httpCode}";
            echo "  ✗ [{$article->id}] 失败: {$errMsg}\n";

            DB::insert("
                INSERT INTO article_sync_log (article_id, target, status, error_message, created_at)
                VALUES (?, ?, 'failed', ?, CURRENT_TIMESTAMP)
                ON CONFLICT (article_id, target) DO NOTHING
            ", [$article->id, $targetName, $errMsg]);
        }
        sleep(1);
    }
}

echo "\n=== 完成 ===\n";
