<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvesteeApplicationSubmitted extends Mailable
{
    use Queueable, SerializesModels;

    public $applicationData;
    public $userId;

    public function __construct($data, $userId)
    {
        $this->applicationData = $data;
        $this->userId = $userId;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Investee Application Submitted',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.investee-application-submitted',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}