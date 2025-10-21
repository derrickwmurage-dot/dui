<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InvestmentRequest extends Mailable
{
    use Queueable, SerializesModels;

    public $investmentDetails;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($investmentDetails)
    {
        $this->investmentDetails = $investmentDetails;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject('New Investment Request')
                    ->view('emails.marketplace.investment-request');
    }
}