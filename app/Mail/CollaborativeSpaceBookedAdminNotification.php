<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CollaborativeSpaceBookedAdminNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $paymentData;
    public $verificationData;
    public $userEmail;

    public function __construct($paymentData, $verificationData, $userEmail)
    {
        $this->paymentData = $paymentData;
        $this->verificationData = $verificationData;
        $this->userEmail = $userEmail;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Collaborative Space Booked and Paid',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.collaborative-space-booked-admin-notification',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}