<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Tests\Helpers\PayloadGenerator;

// Mock a user for auth-dependent code
$user = \App\Modules\Api\V1\User\Models\User::first();
auth()->login($user);

$payload = PayloadGenerator::generate('Lead');
echo "Generated Lead Payload:\n";
print_r($payload);
