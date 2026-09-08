<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreserveNotificationPollingFlash
{
    /** Pembacaan notifikasi bukan navigasi halaman; pesan dan input harus menunggu halaman tujuan. */
    public function handle(Request $request, Closure $next): Response
    {
        $request->session()->reflash();

        return $next($request);
    }
}
