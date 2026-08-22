<?php

namespace App\Services\GeoFlow;

use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;

class GeoFlowAgentPublisher implements DistributionPublisherInterface
{
    public function __construct(private readonly DistributionHttpClient $httpClient) {}

    public function health(DistributionChannel $channel): array
    {
        return $this->httpClient->health($channel);
    }

    public function publish(ArticleDistribution $distribution, array $payload): array
    {
        return $this->httpClient->send($distribution, $payload);
    }

    public function update(ArticleDistribution $distribution, array $payload): array
    {
        return $this->httpClient->updateArticle($distribution, $payload);
    }

    public function delete(ArticleDistribution $distribution): array
    {
        return $this->httpClient->deleteArticle($distribution);
    }

    public function syncSiteSettings(DistributionChannel $channel): array
    {
        return $this->httpClient->syncSiteSettings($channel);
    }

    /**
     * 根据标题/关键词自动推荐目标站栏目。
     *
     * @param  array{title:string, keywords?:string, content?:string}  $context
     * @return array{success:bool, category_id?:int, category_name?:string, reason?:string}
     */
    public function autoClassify(DistributionChannel $channel, array $context): array
    {
        return $this->httpClient->autoClassify($channel, $context);
    }
}
