<?php

namespace App\Services\GeoFlow;

use App\Exceptions\DistributionAcknowledgedResponseException;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Support\Site\ArticlePermalinkPolicy;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use RuntimeException;

class DistributionHttpClient
{
    public function __construct(
        private readonly DistributionSigningService $signingService,
        private readonly SafeOutboundHttpClient $safeHttp,
        private readonly Factory $http,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function send(ArticleDistribution $distribution, array $payload): array
    {
        return $this->signedJsonRequest($distribution, '/geoflow-agent/v1/articles', 'article.publish', (string) $distribution->idempotency_key, $payload, '目标站分发');
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function updateArticle(ArticleDistribution $distribution, array $payload): array
    {
        $distribution->loadMissing('article');
        $slug = (string) ($distribution->article?->slug ?? '');
        if ($slug === '') {
            throw new RuntimeException('分发文章缺少 slug，无法更新目标站。');
        }

        $path = '/geoflow-agent/v1/articles/'.rawurlencode($slug).'/update';

        return $this->signedJsonRequest($distribution, $path, 'article.update', (string) $distribution->idempotency_key, $payload, '目标站文章更新');
    }

    /**
     * @return array<string,mixed>
     */
    public function deleteArticle(ArticleDistribution $distribution): array
    {
        $distribution->loadMissing('article');
        $slug = (string) ($distribution->article?->slug ?? '');
        if ($slug === '') {
            throw new RuntimeException('分发文章缺少 slug，无法删除目标站副本。');
        }

        $path = '/geoflow-agent/v1/articles/'.rawurlencode($slug).'/delete';

        return $this->signedJsonRequest($distribution, $path, 'article.delete', (string) $distribution->idempotency_key, [
            'version' => '1.0',
            'source' => 'geoflow',
            'event' => 'article.delete',
            'article' => [
                'id' => (int) $distribution->article->id,
                'slug' => $slug,
                'title' => (string) $distribution->article->title,
            ],
        ], '目标站文章删除');
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function signedJsonRequest(ArticleDistribution $distribution, string $path, string $event, string $idempotencyKey, array $payload, string $operation): array
    {
        $distribution->loadMissing(['channel.activeSecret']);
        $channel = $distribution->channel;
        $secret = $channel?->activeSecret;
        if (! $channel || ! $secret) {
            throw new RuntimeException('分发渠道或有效密钥不存在');
        }

        return $this->sendChannelSignedJson($channel, $secret, $path, $event, $idempotencyKey, $payload, $operation);
    }

    /**
     * @return array<string,mixed>
     */
    public function health(DistributionChannel $channel): array
    {
        $channel->loadMissing('activeSecret');
        $body = '{}';
        $path = '/geoflow-agent/v1/health';
        $request = $this->http->timeout(10)->connectTimeout(3)->acceptJson();
        if ($channel->activeSecret) {
            $request = $request->withHeaders($this->signingService->headers(
                $channel->activeSecret,
                'GET',
                $path,
                $body,
                'health.check',
                'health-channel-'.(int) $channel->id.'-'.time()
            ));
        }

        $endpoint = $this->endpoint($channel, $path);
        $response = $this->safeHttp->get($request, $endpoint, $this->jsonMaxBytes());
        if ($response->status() === 404 && $this->canUseIndexPhpFallback($channel)) {
            $fallbackEndpoint = $this->indexPhpEndpoint($channel, $path);
            $fallbackResponse = $this->safeHttp->get($request, $fallbackEndpoint, $this->jsonMaxBytes());
            if (! $fallbackResponse->failed()) {
                $json = $fallbackResponse->json();
                $result = is_array($json) ? $json : ['ok' => true];
                $result['agent_base_url'] = $this->indexPhpBaseUrl($channel);

                return $result;
            }
        }

        if ($response->failed()) {
            throw new DistributionHttpException($this->failureMessage('目标站健康检查', $response), $response->status());
        }

        $json = $response->json();

        return is_array($json) ? $json : ['ok' => true];
    }

    /**
     * @return array<string,mixed>
     */
    public function frontendCapabilities(DistributionChannel $channel): array
    {
        $channel->loadMissing('activeSecret');
        $secret = $channel->activeSecret;
        if (! $secret) {
            throw new RuntimeException('分发渠道有效密钥不存在');
        }

        $path = '/geoflow-agent/v1/frontend-capabilities';

        return $this->signedGetJson(
            $channel,
            $secret,
            $path,
            'frontend.capabilities',
            'frontend-capabilities-channel-'.(int) $channel->id.'-'.time(),
            '目标站前台能力读取'
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function syncSiteSettings(DistributionChannel $channel, ?string $idempotencyKey = null, ?array $settings = null): array
    {
        $channel->loadMissing('activeSecret');
        $secret = $channel->activeSecret;
        if (! $secret) {
            throw new RuntimeException('分发渠道有效密钥不存在');
        }

        $path = '/geoflow-agent/v1/site-settings';
        $settings ??= $channel->targetSiteSettingsPayload();
        $policy = ArticlePermalinkPolicy::fromRaw($settings[ArticlePermalinkPolicy::SETTING_KEY] ?? null);
        $capabilities = $channel->frontendCapabilitiesCache();
        if ($policy->currentPattern !== ArticlePermalinkPolicy::DEFAULT_PATTERN
            && (! $capabilities['supports_article_permalink_policy']
                || ! in_array(1, $capabilities['article_permalink_schema_versions'], true))) {
            throw new RuntimeException(__('article_permalink.errors.agent_capability_required'));
        }

        $result = $this->sendChannelSignedJson($channel, $secret, $path, 'site.settings.update', $idempotencyKey ?: 'site-settings-channel-'.(int) $channel->id.'-'.time(), [
            'settings' => $settings,
            'expected_article_permalink_revision' => (int) $capabilities['current_article_permalink_revision'],
        ], '目标站点设置同步');

        if (isset($result['article_permalink_revision'])) {
            $capabilities['current_article_permalink_revision'] = max(0, (int) $result['article_permalink_revision']);
            $capabilities['current_article_permalink_pattern'] = trim((string) ($result['article_permalink_pattern'] ?? $policy->currentPattern));
            $channel->fillFrontendCapabilitiesCache($capabilities)->save();
        }

        return $result;
    }

    /**
     * 调用目标站 sync_article.php?action=auto_classify 接口，根据标题和关键词自动推荐栏目。
     *
     * @param  array{title:string, keywords?:string, content?:string}  $context
     * @return array{success:bool, category_id?:int, category_name?:string, reason?:string}
     */
    public function autoClassify(DistributionChannel $channel, array $context): array
    {
        $channel->loadMissing('activeSecret');
        $secret = $channel->activeSecret;
        if (! $secret) {
            return ['success' => false, 'reason' => '分发渠道有效密钥不存在'];
        }

        $path = '/sync_article.php';
        $payload = [
            'action' => 'auto_classify',
            'title' => $context['title'],
        ];
        if (! empty($context['keywords'])) {
            $payload['keywords'] = $context['keywords'];
        }
        if (! empty($context['content'])) {
            $payload['content'] = $context['content'];
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($body)) {
            return ['success' => false, 'reason' => '载荷 JSON 编码失败'];
        }

        $endpoint = $this->endpoint($channel, $path);
        $response = $this->postSignedJson($secret, $endpoint, $path, $body, 'article.auto_classify', 'auto-classify-'.time(), 30);

        if ($response->status() === 404 && $this->canUseIndexPhpFallback($channel)) {
            $fallbackEndpoint = $this->indexPhpEndpoint($channel, $path);
            $fallbackResponse = $this->postSignedJson($secret, $fallbackEndpoint, $path, $body, 'article.auto_classify', 'auto-classify-'.time(), 30);
            if (! $fallbackResponse->failed()) {
                return $this->decodeAutoClassifyResponse($fallbackResponse);
            }
        }

        if ($response->failed()) {
            return ['success' => false, 'reason' => 'HTTP '.$response->status().': '.$this->summarizeResponseBody($response->body())];
        }

        return $this->decodeAutoClassifyResponse($response);
    }

    /**
     * @return array{success:bool, category_id?:int, category_name?:string, reason?:string}
     */
    private function decodeAutoClassifyResponse(Response $response): array
    {
        $json = $response->json();
        if (! is_array($json)) {
            return ['success' => false, 'reason' => '目标站返回非 JSON 响应'];
        }

        // 格式1: chengenyiliao.cn 风格 {code:0, data:{category_id:xx, ...}}
        if (isset($json['code']) && isset($json['data']) && is_array($json['data'])) {
            if ((int) $json['code'] === 0) {
                $data = $json['data'];
                return [
                    'success' => true,
                    'category_id' => isset($data['category_id']) ? (int) $data['category_id'] : null,
                    'category_name' => $data['category_name'] ?? null,
                    'reason' => $data['message'] ?? null,
                ];
            }
            return ['success' => false, 'reason' => $json['message'] ?? '目标站返回错误'];
        }

        // 格式2: 标准风格 {success:true, category_id:xx, ...}
        if (! ($json['success'] ?? false)) {
            return ['success' => false, 'reason' => $json['reason'] ?? '未知错误'];
        }

        return [
            'success' => true,
            'category_id' => isset($json['category_id']) ? (int) $json['category_id'] : null,
            'category_name' => $json['category_name'] ?? null,
            'reason' => $json['reason'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function sendChannelSignedJson(DistributionChannel $channel, DistributionChannelSecret $secret, string $path, string $event, string $idempotencyKey, array $payload, string $operation): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($body)) {
            throw new RuntimeException($operation.'载荷 JSON 编码失败');
        }

        $endpoint = $this->endpoint($channel, $path);
        // 大站点（如 90817 4000+ 篇文章）的 Agent publish 会做静态重建，耗时可达 30-60s，
        // 30s 会让客户端先超时断开（nginx 记 499）而服务端其实发布成功。放宽到 120s。
        $response = $this->postSignedJson($secret, $endpoint, $path, $body, $event, $idempotencyKey, 120);
        $resolvedEndpoint = null;
        if ($response->status() === 404 && $this->canUseIndexPhpFallback($channel)) {
            $fallbackBaseUrl = $this->indexPhpBaseUrl($channel);
            $fallbackResponse = $this->postSignedJson($secret, $this->indexPhpEndpoint($channel, $path), $path, $body, $event, $idempotencyKey, 120);
            $response = $fallbackResponse;
            if (! $fallbackResponse->failed()) {
                $resolvedEndpoint = $fallbackBaseUrl;
            }
        }
        if ($response->failed()) {
            throw new DistributionHttpException($this->failureMessage($operation, $response), $response->status());
        }
        $result = $this->decodeJson($response);
        try {
            if ($resolvedEndpoint !== null) {
                $channel->forceFill(['endpoint_url' => $resolvedEndpoint])->save();
            }
            $secret->forceFill(['last_used_at' => now()])->save();
        } catch (\Throwable $exception) {
            throw new DistributionAcknowledgedResponseException($result, $exception);
        }

        return $result;
    }

    private function postSignedJson(DistributionChannelSecret $secret, string $endpoint, string $path, string $body, string $event, string $idempotencyKey, int $timeout): Response
    {
        $request = $this->http->timeout($timeout)
            ->connectTimeout(5)
            ->withHeaders($this->signingService->headers(
                $secret,
                'POST',
                $path,
                $body,
                $event,
                $idempotencyKey
            ))
            ->withBody($body, 'application/json');

        return $this->safeHttp->send($request, 'POST', $endpoint, [], $this->jsonMaxBytes());
    }

    /**
     * @return array<string,mixed>
     */
    private function signedGetJson(DistributionChannel $channel, DistributionChannelSecret $secret, string $path, string $event, string $idempotencyKey, string $operation): array
    {
        $body = '{}';
        $request = $this->http->timeout(10)
            ->connectTimeout(3)
            ->acceptJson()
            ->withHeaders($this->signingService->headers(
                $secret,
                'GET',
                $path,
                $body,
                $event,
                $idempotencyKey
            ));

        $endpoint = $this->endpoint($channel, $path);
        $response = $this->safeHttp->get($request, $endpoint, $this->jsonMaxBytes());
        $failedResponse = $response;

        if ($response->status() === 404 && $this->canUseIndexPhpFallback($channel)) {
            $fallbackEndpoint = $this->indexPhpEndpoint($channel, $path);
            $fallbackResponse = $this->safeHttp->get($request, $fallbackEndpoint, $this->jsonMaxBytes());
            $failedResponse = $fallbackResponse;

            if (! $fallbackResponse->failed()) {
                $result = $this->decodeJson($fallbackResponse);
                $result['agent_base_url'] = $this->indexPhpBaseUrl($channel);

                return $result;
            }
        }

        if ($failedResponse->failed()) {
            throw new DistributionHttpException($this->failureMessage($operation, $failedResponse), $failedResponse->status());
        }

        return $this->decodeJson($response);
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(Response $response): array
    {
        $json = $response->json();

        return is_array($json) ? $json : ['ok' => true];
    }

    private function jsonMaxBytes(): int
    {
        return (int) config('geoflow.outbound_json_max_bytes', 4 * 1024 * 1024);
    }

    private function endpoint(DistributionChannel $channel, string $path): string
    {
        return rtrim((string) $channel->endpoint_url, '/').$path;
    }

    private function canUseIndexPhpFallback(DistributionChannel $channel): bool
    {
        return ! str_ends_with(rtrim((string) $channel->endpoint_url, '/'), '/index.php');
    }

    private function indexPhpBaseUrl(DistributionChannel $channel): string
    {
        return rtrim((string) $channel->endpoint_url, '/').'/index.php';
    }

    private function indexPhpEndpoint(DistributionChannel $channel, string $path): string
    {
        return $this->indexPhpBaseUrl($channel).$path;
    }

    private function failureMessage(string $operation, Response $response): string
    {
        $status = $response->status();
        if ($status === 404) {
            return '目标站 Agent 接口未找到。请先在渠道详情页下载“目标站点包”，上传解压到目标站，并确认 Web 服务器入口指向站点包的 public/index.php；如果部署在二级目录，请把 Agent 基础地址填写为包含该目录的入口地址。';
        }

        if (in_array($status, [401, 403], true)) {
            return $operation.'失败：HTTP '.$status.'，目标站 Agent 鉴权未通过。请确认密钥 ID 和密钥明文已写入目标站点包配置，并且渠道密钥没有被重置。';
        }

        return $operation.'失败：HTTP '.$status;
    }
}
