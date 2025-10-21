<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CollaborativeSpaceBookingConfirmed extends Mailable
{
    use Queueable, SerializesModels;

    public $paymentData;
    public $verificationData;

    public function __construct($paymentData, $verificationData)
    {
        $this->paymentData = $paymentData;
        $this->verificationData = $verificationData;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Collaborative Space Booking Confirmed',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.collaborative-space-booking-confirmed',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}