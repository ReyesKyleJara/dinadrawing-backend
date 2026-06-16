<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UpcomingPlanReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $userName,
        public string $planName,
        public string $dateTime,
        public string $actionUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Upcoming plan: ' . $this->planName,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.upcoming-plan-reminder',
            with: [
                'userName' => $this->userName,
                'planName' => $this->planName,
                'dateTime' => $this->dateTime,
                'actionUrl' => $this->actionUrl,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
