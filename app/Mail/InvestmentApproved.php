<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvestmentApproved extends Mailable
{
    use Queueable, SerializesModels;

    public $investorName;
    public $marketplaceTitle;
    public $investmentAmount;
    public $equity;

    public function __construct($investorName, $marketplaceTitle, $investmentAmount, $equity)
    {
        $this->investorName = $investorName;
        $this->marketplaceTitle = $marketplaceTitle;
        $this->investmentAmount = $investmentAmount;
        $this->equity = $equity;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Investment Has Been Approved',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.investment.approved',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}