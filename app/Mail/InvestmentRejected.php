<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvestmentRejected extends Mailable
{
    use Queueable, SerializesModels;

    public $investorName;
    public $marketplaceTitle;
    public $reason;

    public function __construct($investorName, $marketplaceTitle, $reason = 'No specific reason provided.')
    {
        $this->investorName = $investorName;
        $this->marketplaceTitle = $marketplaceTitle;
        $this->reason = $reason;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Investment Request Was Not Approved',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.investment.rejected',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}