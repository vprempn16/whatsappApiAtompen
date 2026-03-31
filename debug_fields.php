<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\CrmField;

$module = 'Activity';
$fields = CrmField::where('modulename', $module)->get();

echo "Fields for module: $module\n";
foreach ($fields as $field) {
    echo "ID: {$field->id} | FieldName: {$field->fieldname} | APIFieldName: {$field->apifieldname} | Label: {$field->fieldlabel} | Mandatory: {$field->mandatory}\n";
}
