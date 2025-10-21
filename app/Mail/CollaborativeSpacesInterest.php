<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CollaborativeSpacesInterest extends Mailable
{
    use Queueable, SerializesModels;

    public $bookingData;
    public $bookingId;

    public function __construct($bookingData, $bookingId)
    {
        $this->bookingData = $bookingData;
        $this->bookingId = $bookingId;
    }

    public function build()
    {
        return $this->subject('New Interest in ' . $this->bookingData['studioName'])
                    ->view('emails.collabspace.collaborative_spaces_interest')
                    ->with([
                        'userName' => $this->bookingData['userName'],
                        'studioName' => $this->bookingData['studioName'],
                        'startDate' => $this->bookingData['startDate']->toDateString(),
                        'days' => $this->bookingData['days'],
                        'amount' => $this->bookingData['amount'],
                        'additionalInfo' => $this->bookingData['additionalInfo'],
                        'bookingId' => $this->bookingId, // Pass bookingId to the template
                    ]);
    }
}