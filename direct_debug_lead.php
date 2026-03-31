<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Services\CRM\RecordObject;
use Illuminate\Support\Facades\Auth;

$user = User::where('email', 'admin@atompen.test')->first();
if (!$user) {
    die("Admin user not found\n");
}
Auth::login($user);

$data = [
    'firstName' => 'Debug',
    'lastName' => 'Test ' . time(),
    'email' => 'debug' . time() . '@example.com',
    'phoneNumber' => '9999999999',
    'leadStatus' => 'New',
];

echo "Directly creating Lead via RecordObject::make...\n";

try {
    $model = RecordObject::make('Lead', null, $data, 'CreateView');
    echo "Model initialized. Table: " . $model->getTable() . "\n";

    $saved = $model->save();

    if ($saved) {
        echo "Successfully saved! ID: " . $model->id . "\n";
    } else {
        echo "Save returned false.\n";
    }
} catch (\Exception $e) {
    echo "Exception Caught: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace snippet:\n" . substr($e->getTraceAsString(), 0, 1000) . "...\n";
}
