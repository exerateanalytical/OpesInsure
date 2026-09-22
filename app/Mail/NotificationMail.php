<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A plain-text envelope for whatever body NotificationDispatchService already
 * rendered from an approved notification_templates row — no separate Blade
 * view, since the template's own body is the entire content.
 */
final class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(private readonly string $renderedSubject, private readonly string $renderedBody)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->renderedSubject !== '' ? $this->renderedSubject : 'OpesInsure');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.notification', with: ['body' => $this->renderedBody]);
    }
}
