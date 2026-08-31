<?php

namespace App\Services\Notifications\WhatsApp;

/**
 * Adapter aman saat provider WhatsApp belum berstatus aktif.
 *
 * Tidak ada HTTP request yang boleh dilakukan sebelum seluruh artefak provider
 * dan sumber penerima disetujui secara formal.
 */
class UnavailableWhatsAppTemplateAdapter implements WhatsAppTemplateAdapter
{
    public function send(WhatsAppTemplateMessage $message): WhatsAppDeliveryResult
    {
        return WhatsAppDeliveryResult::unavailable();
    }
}
