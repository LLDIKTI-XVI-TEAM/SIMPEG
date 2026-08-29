<?php

namespace App\Services\Notifications\WhatsApp;

final class WhatsAppPrivacyGuard
{
    /**
     * Memindai teks untuk memastikan tidak mengandung data pribadi rahasia (NIK/KK 16 digit, password, token).
     */
    public function isSafe(?string $text): bool
    {
        if ($text === null || trim($text) === '') {
            return true;
        }

        // Pola 16 digit NIK atau Nomor Kartu Keluarga (termasuk format dengan pemisah spasi, tanda hubung, atau titik)
        if (preg_match('/\b(?:\d[\s\-\.]{0,2}){15}\d\b/', $text) === 1) {
            return false;
        }

        // Pola token JWT / base64 header (eyJ...)
        if (preg_match('/eyJ[a-zA-Z0-9_\-]{16,}/', $text) === 1) {
            return false;
        }

        // Pola kata kunci token
        if (preg_match('/\b(token|secret|api_key)\s*[:=]\s*\S+/i', $text) === 1) {
            return false;
        }

        // Pola kredensial kata sandi
        if (preg_match('/\b(password|kata\s*sandi|passphrase)\s*[:=]\s*\S+/i', $text) === 1) {
            return false;
        }

        // Pola Authorization Bearer Token
        if (preg_match('/\b(bearer)\s+[a-zA-Z0-9_\-\.]{16,}/i', $text) === 1) {
            return false;
        }

        // Pola informasi finansial rahasia (nomor rekening bank, virtual account, kartu kredit/debit, PIN, CVV)
        if (preg_match('/\b(?:no(?:mor)?\.?\s*)?(?:rek(?:ening)?|va|virtual\s*account|bank\s+[a-zA-Z]+)\b[^\d\n\r]{0,30}\d+/i', $text) === 1) {
            return false;
        }

        if (preg_match('/\b(?:kartu\s*(?:kredit|debit)|credit\s*card|debit\s*card|cvv|cvc|pin)\b[^\d\n\r]{0,20}\d+/i', $text) === 1) {
            return false;
        }

        return true;
    }

    /**
     * Memvalidasi seluruh nilai variabel template.
     *
     * @param  array<int|string, string>  $variables
     */
    public function areVariablesSafe(array $variables): bool
    {
        foreach ($variables as $value) {
            if (! $this->isSafe($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Menyanitasi teks dari script, tag HTML, karakter kontrol, dan perulangan whitespace.
     */
    public function sanitize(?string $text): string
    {
        if ($text === null) {
            return '';
        }

        // Hapus blok script dan style beserta isinya
        $cleaned = (string) preg_replace('/<(script|style)\b[^>]*>(.*?)<\/\1>/is', '', $text);

        // Hapus sisa tag HTML
        $cleaned = strip_tags($cleaned);

        // Hapus karakter kontrol ASCII non-printable
        $cleaned = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $cleaned);

        // Normalisasi whitespace dan baris baru menjadi spasi tunggal
        $cleaned = (string) preg_replace('/\s+/u', ' ', $cleaned);

        return trim($cleaned);
    }
}
