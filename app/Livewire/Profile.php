<?php

namespace App\Livewire;

use Livewire\Component;
use Mary\Traits\Toast;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Exception\Auth\SendActionLinkFailed;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use App\Mail\ContactFormMail;

class Profile extends Component
{
    use Toast;

    public $kycSubmitted = false;
    public $businessVerificationStatus;
    public $completionPercentage;
    public $emailDisplay;
    public $lastEmailSentAt = null;
    public $cooldownTime = 60; 
    public $remainingAttempts = 5;
    public $isButtonDisabled = false;

    // Contact form rate limiting
    public $contactCooldownTime = 300; 
    public $lastContactSentAt = null;
    public $remainingContactAttempts = 5;
    public $isContactButtonDisabled = false;

    public function mount()
    {
        $this->checkKYCSubmission();
        $this->fetchBusinessVerificationStatus();
        $this->fetchUserEmail();
        
        // Reset everything to initial state
        $this->remainingAttempts = session('password_reset_attempts', 5);
        $this->lastEmailSentAt = null;
        $this->isButtonDisabled = false;
        
        // Clear any existing session data for testing
        session()->forget('last_email_sent_at');
        
        // Initialize contact form rate limiting
        $this->remainingContactAttempts = session('contact_form_attempts', 5);
        $this->lastContactSentAt = null;
        $this->isContactButtonDisabled = false;
        
        // Clear contact form session data if testing
        session()->forget('last_contact_sent_at');
    }

    public function fetchUserEmail()
    {
        $userId = session('firebase_user');

        if (!$userId) {
            $this->error('Please log in to access this feature.');
            return;
        }

        $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
        $auth = $factory->createAuth();
        $user = $auth->getUser($userId);

        $this->emailDisplay = $user->email ?? 'Email not found';
    }

    public function fetchBusinessVerificationStatus()
    {
        $userId = session('firebase_user');
        $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
        $firestore = $factory->createFirestore();
        $collection = $firestore->database()->collection('Profile');
        $document = $collection->document($userId)->snapshot();

        if ($document->exists()) {
            $data = $document->data();
            $this->completionPercentage = $data['completion']['completion'] ?? 0;
            $this->businessVerificationStatus = $this->completionPercentage > 70 ? 'Verified' : 'Not Verified';
        } else {
            $this->businessVerificationStatus = 'Not Verified';
            $this->completionPercentage = 0;
        }
    }

    public function redirectToBusinessVerification()
    {
        return redirect()->route('profile.business-verification');
    }

    // KYC verification
    public function redirectToKYC(){
        return redirect()->route('profile.kyc');
    }

    public function checkKYCSubmission()
    {
        $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
        $firestore = $factory->createFirestore();
        $collection = $firestore->database()->collection('KYC');
        $documents = $collection->where('userId', '=', session('firebase_user'))->documents();

        if ($documents->isEmpty()) {
            $this->kycSubmitted = false;
        } else {
            $this->kycSubmitted = true;
        }
    }

    // Privacy Policy
    public function redirectToPrivacyPolicy()
    {
        return redirect()->route('profile.privacy-policy');
    }

    // Learn more details
    public $learnMoreModal = false;

    public function openLearnMoreModal()
    {
        $this->learnMoreModal = true;
    }

    public function closeLearnMoreDetails()
    {
        $this->learnMoreModal = false;
    }

    // Password reset vars
    public $email;

    public function sendResetPasswordEmail()
    {
        if ($this->remainingAttempts <= 0) {
            $this->error('Maximum password reset attempts reached for this session.');
            return;
        }

        $now = Carbon::now();
        if ($this->lastEmailSentAt) {
            $lastSent = Carbon::parse($this->lastEmailSentAt);
            if ($now->diffInSeconds($lastSent) < $this->cooldownTime) {
                $remainingTime = $this->cooldownTime - $now->diffInSeconds($lastSent);
                $this->error("Please wait {$remainingTime} seconds before requesting another reset link.");
                return;
            }
        }

        try {
            $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            $auth = $factory->createAuth();
            $auth->sendPasswordResetLink($this->email);
            
            $this->remainingAttempts--;
            $this->lastEmailSentAt = $now;
            $this->isButtonDisabled = true;
            
            session(['last_email_sent_at' => $this->lastEmailSentAt]);
            session(['password_reset_attempts' => $this->remainingAttempts]);
            
            $this->success('Password reset email sent. You have ' . $this->remainingAttempts . ' attempts remaining.');
            
        } catch (SendActionLinkFailed $e) {
            $this->error('Failed to send password reset email: ' . $e->getMessage());
        }
    }

    public function getRemainingCooldownProperty()
    {
        return 0; // Default to 0 until email is actually sent
    }

    // Contact Details
    public $contactModal = false;
    public $contactFirstName;
    public $contactLastName;
    public $contactCompanyName;
    public $contactEmail;
    public $contactMessage;

    public function openContactModal()
    {
        $this->contactModal = true;
    }

    public function closeContactModal()
    {
        $this->contactModal = false;
    }

    public function sendContactForm()
    {
        // Check remaining attempts
        if ($this->remainingContactAttempts <= 0) {
            $this->error('Maximum contact form submissions reached for this session. Please try again later.');
            return;
        }

        // Check cooldown
        $now = Carbon::now();
        if ($this->lastContactSentAt) {
            $lastSent = Carbon::parse($this->lastContactSentAt);
            if ($now->diffInSeconds($lastSent) < $this->contactCooldownTime) {
                $remainingTime = ceil(($this->contactCooldownTime - $now->diffInSeconds($lastSent)) / 60);
                $this->error("Please wait {$remainingTime} minutes before sending another message.");
                return;
            }
        }

        $this->validate([
            'contactFirstName' => 'required',
            'contactLastName' => 'required',
            'contactEmail' => 'required|email',
            'contactCompanyName' => 'required',
            'contactMessage' => 'required',
        ]);

        try {
            $contactData = [
                'first_name' => $this->contactFirstName,
                'last_name' => $this->contactLastName,
                'email' => $this->contactEmail,
                'company_name' => $this->contactCompanyName,
                'message' => $this->contactMessage,
            ];

            Mail::to('munyaolance1@gmail.com')
            ->bcc(config('mail.admin.to'))
            ->send(new ContactFormMail($contactData));

            // Update attempts and timestamp
            $this->remainingContactAttempts--;
            $this->lastContactSentAt = $now;
            $this->isContactButtonDisabled = true;
            
            // Store in session
            session(['last_contact_sent_at' => $this->lastContactSentAt]);
            session(['contact_form_attempts' => $this->remainingContactAttempts]);

            $this->resetContactForm();
            $this->closeContactModal();
            $this->success('Your message has been sent successfully! You have ' . 
                         $this->remainingContactAttempts . ' submissions remaining.');
        } catch (\Exception $e) {
            $this->error('Failed to send message. Please try again later.');
        }
    }

    public function resetContactForm()
    {
        $this->contactFirstName = '';
        $this->contactLastName = '';
        $this->contactCompanyName = '';
        $this->contactEmail = '';
        $this->contactMessage = '';
    }

    public function redirectToTermsAndConditions()
    {
        return redirect()->route('profile.terms-and-conditions');
    }

    public function render()
    {
        return view('livewire.profile');
    }
}