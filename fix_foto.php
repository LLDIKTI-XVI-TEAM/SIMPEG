<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$count = \App\Models\Employee::where('foto', '0')->update(['foto' => null]);
echo "Updated $count employees\n";

$jordan = \App\Models\Employee::where('nama_lengkap', 'like', '%Jordan%')->first();
if ($jordan) {
    echo "Jordan foto: " . var_export($jordan->getRawOriginal('foto'), true) . "\n";
}
