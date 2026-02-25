<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Modules\Api\V1\Workflow\Services\WorkflowService;
use Illuminate\Support\Carbon;

$service = new class extends WorkflowService {
    public function testCompare($recordVal, $op, $val)
    {
        return $this->compare($recordVal, $op, $val);
    }
};

$results = [
    'is_today' => $service->testCompare(now()->format('Y-m-d'), 'is_today', null),
    'is_tomorrow' => $service->testCompare(now()->addDay()->format('Y-m-d'), 'is_tomorrow', null),
    'between' => $service->testCompare(now()->format('Y-m-d'), 'between', now()->subDay()->format('Y-m-d') . ',' . now()->addDay()->format('Y-m-d')),
    'less_than_3_days_ago' => $service->testCompare(now()->subDays(2)->format('Y-m-d'), 'less_than_days_ago', 3),
    'more_than_3_days_ago' => $service->testCompare(now()->subDays(5)->format('Y-m-d'), 'more_than_days_ago', 3),
    'is_enabled_1' => $service->testCompare(1, 'is_enabled', null),
    'is_enabled_true' => $service->testCompare(true, 'is_enabled', null),
    'is_disabled_0' => $service->testCompare(0, 'is_disabled', null),
    'is_disabled_empty' => $service->testCompare('', 'is_disabled', null),
];

echo json_encode($results, JSON_PRETTY_PRINT);

