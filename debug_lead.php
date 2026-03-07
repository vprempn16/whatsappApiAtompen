<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;
use App\Modules\Api\V1\User\Models\User;

$user = User::where('email', 'admin@atompen.test')->first();
if (!$user) {
    die("Admin user not found\n");
}

$token = auth()->login($user);
// Note: In tests we use Bearer token. Here we can use auth()->login() for internal calls
// but many controllers use $request->user().

// Let's simulate a request to /api/v1/Lead/new
$payload = [
    'data' => [
        'values' => [
            'firstName' => 'Test',
            'lastName' => 'User ' . uniqid(),
            'email' => 'test' . uniqid() . '@example.com',
            'phoneNumber' => '1234567890',
            'leadStatus' => 'New',
        ]
    ]
];

echo "Simulating Lead Creation...\n";
try {
    $request = \Illuminate\Http\Request::create('/api/v1/Lead/new', 'POST', $payload);
    $request->headers->set('Authorization', 'Bearer ' . $token);
    $response = $app->make(\Illuminate\Contracts\Http\Kernel::class)->handle($request);

    echo "Status: " . $response->getStatusCode() . "\n";
    echo "Content: " . $response->getContent() . "\n";
} catch (\Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
