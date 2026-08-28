<?php

namespace App\Services\Notifications\WhatsApp;

class UnavailableWhatsAppTemplateAdapter implements WhatsAppTemplateAdapter
{
    /** Adapter baseline tidak melakukan HTTP call sebelum provider resmi tersedia. */
    public function send(WhatsAppTemplateMessage $message): WhatsAppDeliveryResult
    {
        return WhatsAppDeliveryResult::unavailable();
    }
}
