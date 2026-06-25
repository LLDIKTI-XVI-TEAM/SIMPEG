<?php

use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    // Blade Icons & Heroicons — didaftarkan eksplisit karena auto-discovery tidak
    // berjalan secara konsisten di environment container Podman.
    BladeUI\Icons\BladeIconsServiceProvider::class,
    BladeUI\Heroicons\BladeHeroiconsServiceProvider::class,
];
