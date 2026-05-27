<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\DistributionChannel;

$sites = [
    [
        'name' => '程恩医疗器械',
        'domain' => 'www.chengenyiliao.cn',
        'endpoint_url' => 'https://www.chengenyiliao.cn/news',
        'front_mode' => 'static',
        'status' => 'active',
    ],
    [
        'name' => '铁塔厂',
        'domain' => 'www.tietachang.com',
        'endpoint_url' => 'https://www.tietachang.com/news',
        'front_mode' => 'static',
        'status' => 'active',
    ],
    [
        'name' => '90817 Monopole Tower',
        'domain' => '90817.com',
        'endpoint_url' => 'https://90817.com/news',
        'front_mode' => 'static',
        'status' => 'active',
    ],
];

foreach ($sites as $site) {
    $existing = DistributionChannel::where('domain', $site['domain'])->first();
    if ($existing) {
        echo "SKIP: {$site['domain']} already exists (ID={$existing->id})\n";
        continue;
    }
    $ch = DistributionChannel::create($site);
    echo "CREATED: {$site['name']} (ID={$ch->id})\n";
}

echo "\n=== Summary ===\n";
$all = DistributionChannel::all();
foreach ($all as $ch) {
    echo "ID={$ch->id} name={$ch->name} domain={$ch->domain} status={$ch->status}\n";
}
