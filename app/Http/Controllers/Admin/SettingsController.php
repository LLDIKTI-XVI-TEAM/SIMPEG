<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(): View
    {
        return view('admin.settings.index');
    }

    /**
     * Halaman pengaturan sistem belum memiliki penyimpanan.
     *
     * Seluruh input pada formulir hanya terikat ke state di peramban tanpa atribut name, sehingga
     * tidak ada nilai yang sampai ke peladen dan tidak ada apa pun yang dapat disimpan. Karena itu
     * jalur ini tidak menulis audit: mencatat perubahan yang tidak terjadi membuat jejak audit
     * menyesatkan. Pesan berhasil juga tidak dikirim agar operator tidak menyangka konfigurasi
     * sudah berlaku.
     */
    public function update(): RedirectResponse
    {
        return redirect()->route('pengaturan')
            ->with('info', 'Penyimpanan pengaturan sistem belum tersedia sehingga tidak ada perubahan yang disimpan.');
    }
}
