<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvestorApplicationSubmitted extends Mailable
{
    use Queueable, SerializesModels;

    public $applicationData;
    public $userId;
    public $creatorName;
    public $creatorEmail;

    public function __construct($data, $creatorName, $creatorEmail)
    {
        $this->applicationData = $data;
        $this->userId = $data['userId'];
        $this->creatorName = $creatorName;
        $this->creatorEmail = $creatorEmail;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Investor Application Submitted',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.investor-application-submitted', // Ensure this matches the file name
        );
    }

    public function attachments(): array
    {
        return [];
    }
}