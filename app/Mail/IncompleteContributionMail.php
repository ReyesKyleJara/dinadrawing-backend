<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class IncompleteContributionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $userName,
        public string $planName,
        public string $contributionType,
        public string $actionUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Incomplete contribution in ' . $this->planName,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.incomplete-contribution',
            with: [
                'userName' => $this->userName,
                'planName' => $this->planName,
                'contributionType' => $this->contributionType,
                'actionUrl' => $this->actionUrl,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
