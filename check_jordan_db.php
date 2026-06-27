<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$employee = \App\Models\Employee::where('nama_lengkap', 'like', '%Jordan%')->first();
if ($employee) {
    var_dump($employee->getRawOriginal('foto'));
} else {
    echo "Jordan not found\n";
}
