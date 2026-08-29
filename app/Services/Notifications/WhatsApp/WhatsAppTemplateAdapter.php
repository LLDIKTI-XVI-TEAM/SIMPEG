<?php

namespace App\Services\Notifications\WhatsApp;

interface WhatsAppTemplateAdapter
{
    public function send(WhatsAppTemplateMessage $message): WhatsAppDeliveryResult;
}
