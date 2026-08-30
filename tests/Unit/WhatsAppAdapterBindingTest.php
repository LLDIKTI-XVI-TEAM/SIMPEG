<?php

namespace Tests\Unit;

use App\Services\Notifications\NotificationEventCatalog;
use App\Services\Notifications\WhatsApp\QontakWhatsAppTemplateAdapter;
use App\Services\Notifications\WhatsApp\WhatsAppTemplateAdapter;
use Tests\TestCase;

class WhatsAppAdapterBindingTest extends TestCase
{
    public function test_adapter_runtime_qontak_tersedia_di_balik_readiness_dan_kill_switch(): void
    {
        $adapter = $this->app->make(WhatsAppTemplateAdapter::class);

        $this->assertInstanceOf(QontakWhatsAppTemplateAdapter::class, $adapter);
        $this->assertTrue(app(NotificationEventCatalog::class)->hasAdapter('whatsapp_business'));
    }
}
