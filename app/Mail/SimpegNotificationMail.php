<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SimpegNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Membawa salinan notifikasi SIMPEG ke template email tanpa mengekspos payload mentah.
     *
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        private readonly ?array $data = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.notification',
            with: [
                'title' => $this->title,
                'body' => $this->body,
                'ctaUrl' => $this->ctaUrl(),
                'ctaLabel' => 'Lihat di SIMPEG',
            ],
        );
    }

    /**
     * Mengarahkan CTA ke target internal bila tersedia; payload lain tidak dirender ke email.
     */
    private function ctaUrl(): string
    {
        $target = $this->data['url'] ?? $this->data['target_url'] ?? null;

        if ($this->isInternalPath($target)) {
            return url($target);
        }

        return route('dashboard');
    }

    /**
     * Menolak URL absolut agar CTA email tidak bisa diarahkan ke domain eksternal.
     */
    private function isInternalPath(mixed $target): bool
    {
        return is_string($target)
            && str_starts_with($target, '/')
            && ! str_starts_with($target, '//');
    }
}
