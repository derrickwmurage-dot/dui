<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AdminPaymentNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $paymentInfo;

    public function __construct($paymentInfo)
    {
        $this->paymentInfo = $paymentInfo;
    }

    public function build()
    {
        return $this->subject('New Payment Received')
                    ->view('emails.admin.payment_notification')
                    ->with([
                        'userName' => $this->paymentInfo['userName'],
                        'amount' => $this->paymentInfo['amount'],
                        'serviceProviderName' => $this->paymentInfo['serviceProviderName'],
                        'serviceDate' => $this->paymentInfo['serviceDate'],
                        'serviceTime' => $this->paymentInfo['serviceTime'],
                        'reference' => $this->paymentInfo['reference'],
                    ]);
    }
}