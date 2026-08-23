<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Konflik otorisasi saat revalidasi switch role terhadap row terkunci.
 *
 * Terjadi ketika state akun sudah berubah sejak request divalidasi (mis. akun didemosi oleh
 * request paralel), sehingga target role tidak lagi valid saat transaksi memperoleh lock.
 * Dimunculkan sebagai konflik terkontrol (403), bukan 500.
 */
class SwitchRoleConflictException extends RuntimeException {}
