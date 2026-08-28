<?php

namespace Tests\Support;

use App\Services\Notifications\WhatsApp\WhatsAppDeliveryResult;
use App\Services\Notifications\WhatsApp\WhatsAppTemplateAdapter;
use App\Services\Notifications\WhatsApp\WhatsAppTemplateMessage;
use PHPUnit\Framework\Assert;
use Throwable;

class FakeWhatsAppTemplateAdapter implements WhatsAppTemplateAdapter
{
    /** @var list<WhatsAppTemplateMessage> */
    private array $sent = [];

    private ?WhatsAppDeliveryResult $nextResult = null;

    private ?Throwable $nextException = null;

    public function send(WhatsAppTemplateMessage $message): WhatsAppDeliveryResult
    {
        $this->sent[] = $message;

        if ($this->nextException !== null) {
            throw $this->nextException;
        }

        return $this->nextResult ?? WhatsAppDeliveryResult::delivered();
    }

    public function setNextException(Throwable $exception): void
    {
        $this->nextException = $exception;
    }

    public function setNextResult(WhatsAppDeliveryResult $result): self
    {
        $this->nextResult = $result;

        return $this;
    }

    /** @return list<WhatsAppTemplateMessage> */
    public function sentMessages(): array
    {
        return $this->sent;
    }

    public function count(): int
    {
        return count($this->sent);
    }

    public function assertSentCount(int $expectedCount): void
    {
        Assert::assertCount(
            $expectedCount,
            $this->sent,
            "Ekspektasi {$expectedCount} pesan WhatsApp terkirim, tetapi tercatat ".count($this->sent).' pesan.',
        );
    }

    public function assertSent(callable $callback): void
    {
        $matched = array_filter($this->sent, $callback);

        Assert::assertNotEmpty(
            $matched,
            'Tidak ditemukan pesan WhatsApp yang cocok dengan kondisi yang diharapkan.',
        );
    }

    public function assertNotSent(): void
    {
        Assert::assertEmpty(
            $this->sent,
            'Ekspektasi tidak ada pesan WhatsApp terkirim, namun terdapat '.count($this->sent).' pesan.',
        );
    }

    public function reset(): void
    {
        $this->sent = [];
        $this->nextResult = null;
    }
}
