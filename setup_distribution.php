<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Models\Task;
use Illuminate\Support\Str;

$orchestrator = app(DistributionOrchestrator::class);

// Channel -> tasks mapping
$mapping = [
    1 => [4, 8, 9, 17, 18],
    2 => [7, 15, 16],
    3 => [13, 19, 20],
];

foreach ($mapping as $channelId => $taskIds) {
    $channel = DistributionChannel::find($channelId);
    if (!$channel) {
        echo "Channel $channelId not found\n";
        continue;
    }

    // Generate secret
    $existingSecret = $channel->activeSecret;
    if (!$existingSecret) {
        $keyId = 'key_' . Str::random(16);
        $plainSecret = Str::random(32);
        $crypto = app(\App\Support\GeoFlow\ApiKeyCrypto::class);
        $ciphertext = $crypto->encrypt($plainSecret);
        DistributionChannelSecret::create([
            'distribution_channel_id' => $channelId,
            'key_id' => $keyId,
            'secret_ciphertext' => $ciphertext,
            'status' => 'active',
        ]);
        echo "Channel $channelId: secret created ($keyId)\n";
    } else {
        echo "Channel $channelId: already has secret ($existingSecret->key_id)\n";
    }

    // Link tasks
    foreach ($taskIds as $taskId) {
        $task = Task::find($taskId);
        if ($task) {
            $orchestrator->syncTaskChannels($task, [$channelId]);
        }
    }
    echo "Channel $channelId: linked to " . implode(',', $taskIds) . "\n";
}

echo "Done.\n";
