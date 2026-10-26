<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AlertTriggered extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.alert',
            with: ['payload' => $this->payload],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }

    private function subjectLine(): string
    {
        $title = (string) ($this->payload['title'] ?? 'Host alert');
        $status = (string) ($this->payload['status'] ?? 'warning');

        return match ($status) {
            'recovery' => "[ServerDeck] Recovered: {$title}",
            'test' => '[ServerDeck] Test notification',
            'critical' => "[ServerDeck] Critical: {$title}",
            default => "[ServerDeck] Warning: {$title}",
        };
    }
}
