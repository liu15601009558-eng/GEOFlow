<?php
/**
 * 从关键词库生成文章标题
 * 用法: php /var/www/html/bin/generate_titles_from_keywords.php [--library=1|2] [--limit=100] [--dry-run]
 */

require dirname(__DIR__).'/vendor/autoload.php';
$app = require_once dirname(__DIR__).'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\AiModel;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Support\Facades\DB;

// 解析参数
$args = getopt('', ['library:', 'limit:', 'dry-run']);
$libraryId = (int)($args['library'] ?? 0);
$limit = (int)($args['limit'] ?? 50);
$dryRun = isset($args['dry-run']);

if ($libraryId === 0) {
    echo "错误: 必须指定 --library=1 或 --library=2\n";
    echo "  1 = 护理床关键词库\n";
    echo "  2 = 监控塔关键词库\n";
    exit(1);
}

// 产品信息映射
$productInfo = [
    1 => ['name' => '护理床', 'title_library_id' => 4, 'context' => '养老院、康复中心、护理院使用的医疗护理床'],
    2 => ['name' => '铁塔', 'title_library_id' => 3, 'context' => '电力塔、监控塔、通信塔、瞭望塔等各类铁塔产品'],
];

$product = $productInfo[$libraryId];
$titleLibraryId = $product['title_library_id'];
echo "=== 从关键词生成文章标题 ===\n";
echo "关键词库: {$product['name']} (keyword_library_id={$libraryId}, title_library_id={$titleLibraryId})\n";
echo "限制: {$limit} 条\n";
echo "模式: " . ($dryRun ? "试运行" : "正式写入") . "\n\n";

// 获取所有 sensenova API keys（轮转使用）
$crypto = app(ApiKeyCrypto::class);
$apiKeyModels = AiModel::query()->whereIn('id', [44, 45, 46, 47])->where('status', 'active')->get();
$apiKeys = [];
foreach ($apiKeyModels as $m) {
    $apiKeys[] = $crypto->decrypt($m->getRawOriginal('api_key'));
}
$baseUrl = rtrim($apiKeyModels->first()->api_url, '/');
if (preg_match('#/v1$#', $baseUrl)) {
    $apiUrl = $baseUrl . '/chat/completions';
} else {
    $apiUrl = $baseUrl . '/v1/chat/completions';
}
$modelId = 'deepseek-v4-flash';
$keyIndex = 0; // 轮转索引

echo "模型: deepseek-v4-flash\n";
echo "API Keys: " . count($apiKeys) . " 个（轮转使用）\n";
echo "API: {$apiUrl}\n\n";

// 获取已有标题的关键词（跳过已生成的）
$existingTitles = DB::table('titles')
    ->where('library_id', $titleLibraryId)
    ->pluck('keyword')
    ->toArray();
$existingSet = array_flip($existingTitles);

// 获取关键词
$keywords = DB::table('keywords')
    ->where('library_id', $libraryId)
    ->pluck('keyword')
    ->toArray();

// 过滤掉已有标题的关键词
$newKeywords = [];
foreach ($keywords as $kw) {
    if (!isset($existingSet[$kw]) && strlen($kw) >= 3) {
        $newKeywords[] = $kw;
    }
}

echo "总关键词: " . count($keywords) . "\n";
echo "已有标题: " . count($existingTitles) . "\n";
echo "待生成: " . count($newKeywords) . "\n\n";

if (empty($newKeywords)) {
    echo "所有关键词已有标题，无需生成。\n";
    exit(0);
}

// 限制数量
$newKeywords = array_slice($newKeywords, 0, $limit);

// Prompt 模板
$promptTemplate = '{keyword}';

// 处理函数
function callSensenova(string $apiUrl, string $apiKey, string $modelId, string $prompt): ?string {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    $payload = json_encode([
        'model' => $modelId,
        'messages' => [
            ['role' => 'system', 'content' => '你是B2B工业品SEO专家。根据关键词生成文章标题。要求：1.标题必须包含完整关键词 2.15-40个中文字 3.加入规格/场景/优势等修饰词 4.吸引采购商点击 5.只输出标题，不输出其他内容。示例输入：监控塔 示例输出：国标监控塔选型指南：热镀锌防腐工艺与第三方检测报告详解'],
            ['role' => 'user', 'content' => $prompt],
        ],
        'max_tokens' => 200,
        'temperature' => 0.7,
    ], JSON_UNESCAPED_UNICODE);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $hdr = sprintf('Authorization: %s %s', 'Bearer', $apiKey);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        $hdr,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        echo "CURL_ERROR: {$curlError} ";
        return null;
    }
    
    if ($httpCode === 429) {
        echo "RATE_LIMIT ";
        sleep(60); // 限速后等 60 秒
        return null;
    }
    
    if ($httpCode !== 200) {
        echo "HTTP {$httpCode}: " . substr($response, 0, 100) . " ";
        return null;
    }
    
    $data = json_decode($response, true);
    $choices = $data['choices'] ?? [];
    if (empty($choices)) {
        echo "NO_CHOICES ";
        return null;
    }
    $message = $choices[0]['message'] ?? [];
    $text = $message['content'] ?? '';
    $reasoning = $message['reasoning'] ?? '';
    $text = trim($text, "\"'\n\r\t ");
    
    // 如果 content 为空，尝试从 reasoning 提取
    if (strlen($text) < 3 && strlen($reasoning) > 0) {
        // 从 reasoning 中找最后一个引号内的标题
        if (preg_match('/[""「]([^""」]{3,40})[""」]/u', $reasoning, $m)) {
            $text = $m[1];
        }
    }
    
    // 清理：去掉引号、序号等
    $text = preg_replace('/^[\d]+[\.\、]\s*/', '', $text);
    $text = trim($text, "\"'\n\r\t ");
    
    if (strlen($text) < 3) {
        echo "SHORT_CONTENT ";
        return null;
    }
    return $text;
}

// 批量生成
$success = 0;
$failed = 0;
$titles = [];
$maxRetries = 3;
$batchSize = 20; // 每 20 条写入一次数据库

foreach ($newKeywords as $i => $keyword) {
    $prompt = str_replace('{keyword}', $keyword, $promptTemplate);
    
    echo sprintf("[%d/%d] %s → ", $i + 1, count($newKeywords), $keyword);
    
    $title = null;
    for ($retry = 0; $retry < $maxRetries; $retry++) {
        $currentKey = $apiKeys[$keyIndex % count($apiKeys)];
        $title = callSensenova($apiUrl, $currentKey, $modelId, $prompt);
        $keyIndex++; // 轮转到下一个 key
        if ($title) break;
        if ($retry < $maxRetries - 1) {
            usleep(500000); // 重试前等 0.5 秒
        }
    }
    
    if ($title) {
        echo "\"{$title}\"\n";
        $titles[] = [
            'library_id' => $titleLibraryId,
            'title' => $title,
            'keyword' => $keyword,
            'used_count' => 0,
            'usage_count' => 0,
            'is_ai_generated' => true,
            'created_at' => now(),
        ];
        $success++;
    } else {
        echo "FAILED ({$maxRetries} attempts)\n";
        $failed++;
    }
    
    // 每 batchSize 条写入一次数据库
    if (!$dryRun && count($titles) >= $batchSize) {
        DB::table('titles')->insert($titles);
        echo "  [DB] 已写入 " . count($titles) . " 条，累计成功: {$success}\n";
        $titles = [];
    }
    
    // 避免 API 限速：每处理 2 个关键词后等 1 秒
    if (($i + 1) % 2 === 0) {
        sleep(1);
    }
}

// 写入剩余的标题
if (!$dryRun && !empty($titles)) {
    DB::table('titles')->insert($titles);
    echo "  [DB] 已写入剩余 " . count($titles) . " 条\n";
}

// 更新 title_count
if (!$dryRun) {
    $newCount = DB::table('titles')->where('library_id', $titleLibraryId)->count();
    DB::table('title_libraries')
        ->where('id', $titleLibraryId)
        ->update(['title_count' => $newCount, 'updated_at' => now()]);
}

echo "\n=== 结果 ===\n";
echo "成功: {$success}\n";
echo "失败: {$failed}\n";

if (!$dryRun) {
    $finalCount = DB::table('titles')->where('library_id', $titleLibraryId)->count();
    echo "数据库标题总数: {$finalCount}\n";
} else {
    echo "(试运行模式，未写入数据库)\n";
}

echo "\n完成。\n";
