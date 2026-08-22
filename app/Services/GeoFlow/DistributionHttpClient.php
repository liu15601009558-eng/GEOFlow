<?php

namespace App\Services\GeoFlow;

use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DistributionHttpClient
{
    public function __construct(private readonly DistributionSigningService $signingService) {}

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
        $request = Http::timeout(10)->acceptJson();
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
        $response = $request->get($endpoint);
        if ($response->status() === 404 && $this->canUseIndexPhpFallback($channel)) {
            $fallbackEndpoint = $this->indexPhpEndpoint($channel, $path);
            $fallbackResponse = $request->get($fallbackEndpoint);
            if (! $fallbackResponse->failed()) {
                $json = $fallbackResponse->json();
                $result = is_array($json) ? $json : ['ok' => true];
                $result['agent_base_url'] = $this->indexPhpBaseUrl($channel);

                return $result;
            }
        }

        if ($response->failed()) {
            throw new RuntimeException($this->failureMessage('目标站健康检查', $response, $endpoint));
        }

        $json = $response->json();

        return is_array($json) ? $json : ['ok' => true];
    }

    /**
     * @return array<string,mixed>
     */
    public function syncSiteSettings(DistributionChannel $channel): array
    {
        $channel->loadMissing('activeSecret');
        $secret = $channel->activeSecret;
        if (! $secret) {
            throw new RuntimeException('分发渠道有效密钥不存在');
        }

        $path = '/geoflow-agent/v1/site-settings';

        return $this->sendChannelSignedJson($channel, $secret, $path, 'site.settings.update', 'site-settings-channel-'.(int) $channel->id.'-'.time(), [
            'settings' => $channel->targetSiteSettingsPayload(),
        ], '目标站点设置同步');
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
        $response = $this->postSignedJson($secret, $endpoint, $path, $body, $event, $idempotencyKey, 30);
        $failedEndpoint = $endpoint;
        $failedResponse = $response;

        if ($response->status() === 404 && $this->canUseIndexPhpFallback($channel)) {
            $fallbackBaseUrl = $this->indexPhpBaseUrl($channel);
            $fallbackEndpoint = $this->indexPhpEndpoint($channel, $path);
            $fallbackResponse = $this->postSignedJson($secret, $fallbackEndpoint, $path, $body, $event, $idempotencyKey, 30);
            $failedEndpoint = $fallbackEndpoint;
            $failedResponse = $fallbackResponse;

            if (! $fallbackResponse->failed()) {
                $channel->forceFill(['endpoint_url' => $fallbackBaseUrl])->save();
                $secret->forceFill(['last_used_at' => now()])->save();

                return $this->decodeJson($fallbackResponse);
            }
        }

        $secret->forceFill(['last_used_at' => now()])->save();

        if ($failedResponse->failed()) {
            throw new RuntimeException($this->failureMessage($operation, $failedResponse, $failedEndpoint));
        }

        return $this->decodeJson($response);
    }

    private function postSignedJson(DistributionChannelSecret $secret, string $endpoint, string $path, string $body, string $event, string $idempotencyKey, int $timeout): Response
    {
        return Http::timeout($timeout)
            ->withHeaders($this->signingService->headers(
                $secret,
                'POST',
                $path,
                $body,
                $event,
                $idempotencyKey
            ))
            ->withBody($body, 'application/json')
            ->post($endpoint);
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(Response $response): array
    {
        $json = $response->json();

        return is_array($json) ? $json : ['ok' => true];
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

    private function failureMessage(string $operation, Response $response, string $endpoint): string
    {
        $status = $response->status();
        if ($status === 404) {
            return '目标站 Agent 接口未找到（请求地址：'.$endpoint.'）。请先在渠道详情页下载“目标站点包”，上传解压到目标站，并确认 Web 服务器入口指向站点包的 public/index.php；如果部署在二级目录，请把 Agent 基础地址填写为包含该目录的入口地址。';
        }

        if (in_array($status, [401, 403], true)) {
            return $operation.'失败：HTTP '.$status.'，目标站 Agent 鉴权未通过。请确认密钥 ID 和密钥明文已写入目标站点包配置，并且渠道密钥没有被重置。';
        }

        $body = $this->summarizeResponseBody($response->body());

        return $operation.'失败：HTTP '.$status.($body !== '' ? ' '.$body : '');
    }

    private function summarizeResponseBody(string $body): string
    {
        $plain = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/\s+/', ' ', trim($plain));
        if (! is_string($plain) || $plain === '') {
            return '';
        }

        return mb_strlen($plain) > 300 ? mb_substr($plain, 0, 300).'...' : $plain;
    }
}
