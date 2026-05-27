<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\DistributionChannel;
use App\Support\GeoFlow\ApiKeyCrypto;

$builder = app(\App\Services\GeoFlow\DistributionTargetSitePackageBuilder::class);
$crypto = app(ApiKeyCrypto::class);

$channels = DistributionChannel::with('activeSecret')->orderBy('id')->get();

foreach ($channels as $ch) {
    $secret = $ch->activeSecret;
    if (!$secret) {
        echo "Channel {$ch->id}: no active secret, skipping\n";
        continue;
    }
    $plainSecret = $crypto->decrypt($secret->secret_ciphertext);
    if (!$plainSecret) {
        echo "Channel {$ch->id}: decrypt failed, skipping\n";
        continue;
    }
    
    try {
        $result = $builder->build($ch, $secret->key_id, $plainSecret);
        $dest = __DIR__ . '/dist_packages/' . $result['filename'];
        copy($result['path'], $dest);
        echo "Channel {$ch->id} ({$ch->domain}): {$result['filename']} -> {$dest}\n";
        echo "  Key ID: {$secret->key_id}\n";
    } catch (\Exception $e) {
        echo "Channel {$ch->id}: ERROR: {$e->getMessage()}\n";
    }
}

echo "\nDone. Packages in dist_packages/\n";
