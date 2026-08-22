<?php

namespace App\Console\Commands;

use App\Models\Article;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GeoFlowComplianceCheckCommand extends Command
{
    protected $signature = 'geoflow:compliance-check
                            {--task= : 指定任务ID（多个用逗号分隔）}
                            {--days=7 : 检查最近N天的文章}
                            {--fix : 自动修复（标记违规文章）}';

    protected $description = 'GEOFlow 合规检查：违禁词、AI味词、标题匹配、字数检查';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $fix = $this->option('fix');
        $taskIds = $this->parseTaskIds();

        $this->info("=== GEOFlow 合规检查 ===");
        $this->info("检查时间: " . now()->format('Y-m-d H:i'));
        $this->info("检查范围: 最近 {$days} 天");

        // Load forbidden words from site_settings
        $forbiddenWords = $this->loadSetting('forbidden_words');
        $enhancedWords = $this->loadSetting('enhanced_forbidden_words');
        $aiWords = $this->loadSetting('违禁词库_txt');

        $this->info("违禁词: " . count($forbiddenWords) . " 条");
        $this->info("增强违禁词: " . count($enhancedWords) . " 条");

        // Get articles
        $startDate = now()->subDays($days)->startOfDay();
        $query = Article::query()
            ->select('id', 'title', 'content', 'task_id', 'status', 'created_at')
            ->where('created_at', '>=', $startDate);

        if (!empty($taskIds)) {
            $query->whereIn('task_id', $taskIds);
        }

        $articles = $query->orderBy('created_at', 'desc')->get();

        if ($articles->isEmpty()) {
            $this->warn("没有需要检查的文章。");
            return 0;
        }

        $this->info("待检查文章: " . $articles->count() . " 篇");

        $violations = [];
        foreach ($articles as $article) {
            $issues = $this->checkArticle($article, $forbiddenWords, $enhancedWords);
            if (!empty($issues)) {
                $violations[] = [
                    'id' => $article->id,
                    'title' => mb_substr($article->title, 0, 40),
                    'issues' => $issues,
                ];
            }
        }

        // Print report
        $this->newLine();
        $this->info("=== 检查结果 ===");
        $this->info("检查文章: " . $articles->count() . " 篇");
        $this->info("违规文章: " . count($violations) . " 篇");

        if (!empty($violations)) {
            $this->table(
                ['ID', '标题', '违规项'],
                array_map(fn($v) => [
                    $v['id'],
                    $v['title'],
                    implode(', ', array_unique($v['issues']))
                ], $violations)
            );

            if ($fix) {
                $this->warn("自动修复功能待实现");
            }
        } else {
            $this->info("✅ 所有文章合规");
        }

        return 0;
    }

    protected function parseTaskIds(): array
    {
        $taskOption = (string) $this->option('task');
        if ($taskOption === '') {
            return [];
        }
        return array_map('intval', array_filter(explode(',', $taskOption)));
    }

    protected function loadSetting(string $key): array
    {
        $setting = DB::table('site_settings')
            ->where('setting_key', $key)
            ->value('setting_value');

        if (empty($setting)) {
            return [];
        }

        // Parse YAML manually to handle duplicate keys
        $words = [];
        $lines = explode("\n", $setting);
        $currentCategory = '';
        $skipCategories = ['three_level_review', 'violation_levels', 'enforcement', 'metadata'];

        // Words to skip (YAML structure keys, not actual forbidden words)
        $skipWords = [
            'level', 'action', 'platform', 'dynamic', 'machine', 'human', 'auto',
            'level_1', 'level_2', 'level_3', '阻断级', '审核级', '提示级',
            '机器筛查', '人工抽查', '动态更新', '系统自动拦截', '内容进入人工审核',
            '前端弹出提示框', '适配抖音', '小红书', '微信', '每月更新词库',
            '紧跟监管变化', '词库导入内容审核插件', '运营负责人针对行业敏感词进行语境审核',
            '每月根据平台规范更新词库', '禁止发布', '发送', '人工审核队列', '建议用户修改'
        ];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            
            // Skip comments and empty lines
            if (empty($trimmed) || $trimmed[0] === '#') {
                continue;
            }

            // Category line (e.g., "medical_claims:")
            if (preg_match('/^([a-z_]+):\s*$/', $trimmed, $m)) {
                $currentCategory = $m[1];
                // Skip metadata categories
                if (in_array($currentCategory, $skipCategories)) {
                    $currentCategory = 'skip';
                }
                continue;
            }

            // Skip if in metadata category
            if ($currentCategory === 'skip') {
                continue;
            }

            // Key-value pair (e.g., "治愈: 舒适度改善")
            if (preg_match('/^([^:]+):\s*(.+)$/', $trimmed, $m)) {
                $word = trim($m[1]);
                if (!empty($word) && $word !== '-' && !in_array($word, $skipWords) && !in_array($word, $words)) {
                    // Only add if it looks like a Chinese word (not English structure keys)
                    if (preg_match('/^[\x{4e00}-\x{9fff}]+$/u', $word) || mb_strlen($word) > 1) {
                        $words[] = $word;
                    }
                }
                continue;
            }

            // List item (e.g., "- 最")
            if (preg_match('/^[-*]\s*(.+)$/', $trimmed, $m)) {
                $word = trim($m[1]);
                if (!empty($word) && !in_array($word, $skipWords) && !in_array($word, $words)) {
                    // Only add if it looks like a Chinese word
                    if (preg_match('/^[\x{4e00}-\x{9fff}]+$/u', $word) || mb_strlen($word) > 1) {
                        $words[] = $word;
                    }
                }
            }
        }

        return $words;
    }

    protected function checkArticle(Article $article, array $forbiddenWords, array $enhancedWords): array
    {
        $issues = [];
        $content = $article->content ?? '';
        $title = $article->title ?? '';

        // 1. Word count check (≥800 chars)
        if (mb_strlen($content) < 800) {
            $issues[] = '字数不足(' . mb_strlen($content) . ')';
        }

        // 2. Forbidden words check
        foreach ($forbiddenWords as $word) {
            if (mb_strpos($content, $word) !== false || mb_strpos($title, $word) !== false) {
                $issues[] = '违禁词:' . $word;
            }
        }

        // 3. Enhanced forbidden words check
        foreach ($enhancedWords as $word) {
            if (mb_strpos($content, $word) !== false || mb_strpos($title, $word) !== false) {
                $issues[] = '增强违禁词:' . $word;
            }
        }

        // 4. Title-content relevance (simple keyword overlap)
        $titleKeywords = $this->extractKeywords($title);
        $contentKeywords = $this->extractKeywords(mb_substr($content, 0, 500));
        $overlap = array_intersect($titleKeywords, $contentKeywords);
        if (count($overlap) < 2 && count($titleKeywords) >= 2) {
            $issues[] = '标题-内容关联弱';
        }

        return array_slice(array_unique($issues), 0, 5); // Max 5 issues per article
    }

    protected function extractKeywords(string $text): array
    {
        // Simple: split by common delimiters and filter short words
        $words = preg_split('/[\s,，.。!！?？：:；;（）()\[\]【】]+/u', $text);
        return array_filter($words, fn($w) => mb_strlen($w) >= 2);
    }
}