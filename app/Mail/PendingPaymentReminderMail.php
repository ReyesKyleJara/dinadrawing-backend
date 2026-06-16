<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PendingPaymentReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $userName,
        public string $planName,
        public string $amount,
        public string $dueDate,
        public string $actionUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Payment pending in ' . $this->planName,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.pending-payment-reminder',
            with: [
                'userName' => $this->userName,
                'planName' => $this->planName,
                'amount' => $this->amount,
                'dueDate' => $this->dueDate,
                'actionUrl' => $this->actionUrl,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
