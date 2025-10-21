<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class InvestmentApprovedAdminNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $investorEmail;
    public $ownerEmail;
    public $marketplaceTitle;
    public $investorName;
    public $investmentAmount;
    public $equity;

    public function __construct($investorEmail, $ownerEmail, $marketplaceTitle, $investorName, $investmentAmount, $equity)
    {
        $this->investorEmail = $investorEmail;
        $this->ownerEmail = $ownerEmail;
        $this->marketplaceTitle = $marketplaceTitle;
        $this->investorName = $investorName;
        $this->investmentAmount = $investmentAmount;
        $this->equity = $equity;
        // Log to confirm values are received
        Log::info("Admin Notification constructed with: investmentAmount={$this->investmentAmount}, equity={$this->equity}");
    }

    public function build()
    {
        // Log to confirm values before passing to view
        Log::info("Building admin notification email with: investmentAmount={$this->investmentAmount}, equity={$this->equity}");
        return $this->subject('Investment Approved - Schedule Introduction Meeting')
            ->view('emails.investment-approved-admin')
            ->with([
                'investorEmail' => $this->investorEmail,
                'ownerEmail' => $this->ownerEmail,
                'marketplaceTitle' => $this->marketplaceTitle,
                'investorName' => $this->investorName,
                'investmentAmount' => $this->investmentAmount,
                'equity' => $this->equity,
            ]);
    }
}