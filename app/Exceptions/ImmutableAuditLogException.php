<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Ditolaknya perubahan pada audit log. Audit log bersifat append-only karena menjadi
 * satu-satunya bukti siapa mengubah data pegawai dan kapan, sehingga jejaknya tidak
 * boleh dapat dirapikan oleh peran mana pun melalui aplikasi.
 */
class ImmutableAuditLogException extends RuntimeException
{
    public static function untukPembaruan(): self
    {
        return new self('Audit log tidak dapat diubah karena bersifat append-only.');
    }

    public static function untukPenghapusan(): self
    {
        return new self('Audit log tidak dapat dihapus karena bersifat append-only.');
    }
}
