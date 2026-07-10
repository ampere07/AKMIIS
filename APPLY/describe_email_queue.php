<?php
require __DIR__ . '/backend/vendor/autoload.php';
$app = require_once __DIR__ . '/backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $columns = DB::select("DESCRIBE email_queue");
    foreach ($columns as $column) {
        echo "{$column->Field}\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
}
