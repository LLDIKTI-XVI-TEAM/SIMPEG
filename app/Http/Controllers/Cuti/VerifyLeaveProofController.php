<?php

namespace App\Http\Controllers\Cuti;

use App\Http\Controllers\Controller;
use App\Services\Cuti\LeaveProofService;
use Illuminate\Contracts\View\View;

/**
 * Controller publik untuk melakukan verifikasi bukti cuti final secara mandiri tanpa login.
 * Mengambil metadata stempel waktu dari database dan merender halaman verifikasi yang ramah cetak.
 */
class VerifyLeaveProofController extends Controller
{
    /**
     * Memproses pencarian bukti cuti berdasarkan token verifikasi publik.
     */
    public function __invoke(string $token, LeaveProofService $service): View
    {
        $proof = $service->findApprovedByTokenOrFail($token);
        $data = $service->publicViewData($proof);

        $verificationUrl = route('cuti.verify', ['token' => $token]);
        $qrSvg = $service->qrSvgForUrl($verificationUrl);

        return view('cuti.verifikasi', [
            'verification' => $data,
            'verificationUrl' => $verificationUrl,
            'qrSvg' => $qrSvg,
        ]);
    }
}
