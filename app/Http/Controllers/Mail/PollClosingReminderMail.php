<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PollClosingReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $userName,
        public string $planName,
        public string $pollQuestion,
        public string $timeRemaining,
        public string $actionUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Poll closing soon in ' . $this->planName,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.poll-closing-reminder',
            with: [
                'userName' => $this->userName,
                'planName' => $this->planName,
                'pollQuestion' => $this->pollQuestion,
                'timeRemaining' => $this->timeRemaining,
                'actionUrl' => $this->actionUrl,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
