<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AssignmentFinalizedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $userName,
        public string $planName,
        public string $details,
        public string $actionUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Assignment list finalized in ' . $this->planName,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.assignment-finalized',
            with: [
                'userName' => $this->userName,
                'planName' => $this->planName,
                'details' => $this->details,
                'actionUrl' => $this->actionUrl,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
