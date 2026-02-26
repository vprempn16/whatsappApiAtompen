<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

var_dump(method_exists(\App\Modules\Api\V1\Lead\Models\Lead::class, 'getOriginal'));

$lead = \App\Modules\Api\V1\Lead\Models\Lead::first();
if (!$lead) {
    echo "No lead found";
    exit;
}
echo "Lead found: " . $lead->id . "\n";
$old = $lead->getOriginal();
$new = array_merge([], $lead->getAttributes());
echo "Standard leadstatus:\n";
var_dump($old['leadstatus'] ?? 'NOT SET');
var_dump($new['leadstatus'] ?? 'NOT SET');

echo "Custom leadstatus:\n";
var_dump($lead->customAttributes['leadstatus'] ?? 'NOT SET');

$lead->first_name = "Update Name " . rand(1, 100);
var_dump($lead->getOriginal('first_name'));

$hookData = [
    'new_values' => $new,
    'old_values' => $isNew ? [] : $lead->getOriginal(),
];
echo "Is it custom attribute issue?\n";
