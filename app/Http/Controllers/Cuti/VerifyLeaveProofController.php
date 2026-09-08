<?php

namespace App\Http\Controllers\Cuti;

use App\Http\Controllers\Controller;
use App\Services\Cuti\LeaveProofService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller publik untuk melakukan verifikasi bukti cuti final secara mandiri tanpa login.
 * Mengambil metadata stempel waktu dari database dan merender halaman verifikasi yang ramah cetak.
 */
class VerifyLeaveProofController extends Controller
{
    /**
     * Memproses pencarian bukti cuti berdasarkan token verifikasi publik.
     */
    public function __invoke(string $token, LeaveProofService $service): Response
    {
        // Verifikasi harus membaca status terkini, bukan salinan approved lama dari cache browser/proxy.
        $headers = ['Cache-Control' => 'no-store, no-cache, must-revalidate', 'Pragma' => 'no-cache', 'Expires' => '0'];
        try {
            $proof = $service->findIssuedByTokenOrFail($token);
        } catch (ModelNotFoundException) {
            return response()->view('leave.verify', ['proof' => null], 404, $headers);
        }

        $data = $service->publicViewData($proof);

        $verificationUrl = route('cuti.verify', ['token' => $token]);
        $qrSvg = $service->qrSvgForUrl($verificationUrl);

        return response()->view('cuti.verifikasi', [
            'verification' => $data,
            'verificationUrl' => $verificationUrl,
            'qrSvg' => $qrSvg,
        ], 200, $headers);
    }
}
