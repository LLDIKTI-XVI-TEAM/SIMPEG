<?php

use App\Providers\AppServiceProvider;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;

return [
    AppServiceProvider::class,
    // Blade Icons & Heroicons — didaftarkan eksplisit karena auto-discovery tidak
    // berjalan secara konsisten di environment container Podman.
    BladeIconsServiceProvider::class,
    BladeHeroiconsServiceProvider::class,
];
