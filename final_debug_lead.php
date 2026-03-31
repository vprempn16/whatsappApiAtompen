<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Auth;

// Force debug mode to see real errors
Config::set('app.debug', true);
Config::set('errorlog.expose_message_in_debug', true);

$user = User::where('email', 'admin@atompen.test')->first();
if (!$user) {
    die("Admin user not found\n");
}

// Set the user in the guard
Auth::login($user);

// Create Lead payload
$payload = [
    'data' => [
        'values' => [
            'firstName' => 'Debug',
            'lastName' => 'Test',
            'email' => 'debug' . time() . '@example.com',
            'phoneNumber' => '9999999999',
            'leadStatus' => 'New',
        ]
    ]
];

echo "Submitting Lead Creation to /api/v1/Lead/new ...\n";

try {
    $request = \Illuminate\Http\Request::create('/api/v1/Lead/new', 'POST', $payload);
    // Setting user in request as well
    $request->setUserResolver(function () use ($user) {
        return $user;
    });

    $response = $kernel->handle($request);

    echo "HTTP Status: " . $response->getStatusCode() . "\n";
    echo "Response Body: " . $response->getContent() . "\n";

    if ($response->getStatusCode() !== 200) {
        // Find latest error in error_logs if status not 200
        $latest = \App\Models\ErrorLog::orderBy('occurred_at', 'desc')->first();
        if ($latest) {
            echo "\nLatest Error Log (ID: {$latest->error_id}):\n";
            echo "Message: {$latest->full_message}\n";
            echo "Stack Trace: " . substr($latest->stack_trace, 0, 1000) . "...\n";
        }
    }
} catch (\Exception $e) {
    echo "Unexpected Exception: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
