<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class BookingStatusNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $bookingDetails;
    public $status;

    public function __construct($bookingDetails, $status)
    {
        $this->bookingDetails = $bookingDetails;
        $this->status = $status;
    }

    public function build()
    {
        return $this->subject('Booking Status Update')
                    ->view('emails.booking_status')
                    ->with([
                        'bookingDetails' => $this->bookingDetails,
                        'status' => $this->status,
                    ]);
    }
}