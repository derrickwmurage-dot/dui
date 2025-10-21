<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminMarketplacePaymentNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $investmentDetails;
    public $userId;
    public $reference;

    public function __construct($investmentDetails, $userId, $reference)
    {
        $this->investmentDetails = $investmentDetails;
        $this->userId = $userId;
        $this->reference = $reference;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Marketplace Payment Received',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin_marketplace_payment_notification',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
