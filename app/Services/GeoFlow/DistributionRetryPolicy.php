<?php

namespace App\Services\GeoFlow;

use App\Exceptions\PlatformPublishBlockedException;
use App\Services\Outbound\OutboundRequestFailedException;
use DateTimeInterface;
use Throwable;

class DistributionRetryPolicy
{
    public function shouldRetry(Throwable $exception, int $attemptCount, int $maxAttempts): bool
    {
        if ($attemptCount >= $maxAttempts) {
            return false;
        }
        if ($exception instanceof PlatformPublishBlockedException) {
            return false;
        }
        if ($exception instanceof OutboundRequestFailedException) {
            return true;
        }

        $message = mb_strtolower($exception->getMessage(), 'UTF-8');

        if (
            str_contains($message, '401')
            || str_contains($message, '403')
            || str_contains($message, 'signature')
            || str_contains($message, '签名')
            || str_contains($message, '422')
        ) {
            return false;
        }

        // cURL 断连报 "Failed to connect"（error 7）、DNS 失败报 "Could not resolve host"（error 6），
        // 都不含 "connection" 字样，必须单独匹配，否则硬网络错误会被当成永久失败不重试
        return str_contains($message, 'timeout')
            || str_contains($message, 'connection')
            || str_contains($message, 'connect')
            || str_contains($message, 'resolve host')
            || str_contains($message, 'refused')
            || str_contains($message, '429')
            || str_contains($message, '500')
            || str_contains($message, '502')
            || str_contains($message, '503')
            || str_contains($message, '504');
    }

    public function retryAt(int $attemptCount): DateTimeInterface
    {
        return now()->addSeconds(min(3600, 60 * (2 ** max(0, $attemptCount - 1))));
    }
}
