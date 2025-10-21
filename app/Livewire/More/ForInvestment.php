<?php

namespace App\Livewire\More;

use Livewire\Component;
use Kreait\Firebase\Factory;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Mary\Traits\Toast;
use App\Mail\InvesteeApplicationSubmitted;
use App\Mail\InvesteeApplicationUpdated;
use Illuminate\Support\Facades\Mail;

class ForInvestment extends Component
{
    use Toast;

    public $firstName;
    public $secondName;
    public $email;
    public $phone = ''; 
    public $selectedCountry = '+254'; 
    public $phoneDisabled = true; 
    public $ventureName;
    public $website;
    public $industry;
    public $businessModel;
    public $level;
    public $companySolution;
    public $africanLed;
    public $africanPercentage;
    public $femaleFounder;
    public $femalePercentage;
    public $city;
    public $date;
    public $lifecycle;
    public $makeMoney;
    public $generatingRevenue;
    public $amountRaising;
    public $monthsRunway;
    public $externalFunding;
    public $selectedChallenges = [];
    public $documentUrl;
    public $relevantDocuments;
    public $referral;
    public $details;
    public $mailing;
    public $countryOptions = []; // Countries with phone codes
    public $countrynames; // For country names
    public $countryHeadquarters = 'Kenya';
    public $pitchDeck_url;

    public function mount()
    {
        $this->countryOptions = $this->getCountryOptions();
        $this->countrynames = $this->getCountryNamesOptions();
        if (!$this->countryHeadquarters) {
            $this->countryHeadquarters = 'Kenya';
        }
        $this->loadExistingData();

        // Set default country and phone if none exists
        if (!$this->selectedCountry) {
            $this->selectedCountry = 'KE'; // Default to Kenya
            $this->updatePhoneCountryCode('+254');
        }
        $this->phoneDisabled = false; // Enable phone input after initialization
    }

    public function loadExistingData()
    {
        $userId = session('firebase_user');
        $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
        $firestore = $factory->createFirestore();
        $collection = $firestore->database()->collection('InvesteeApplication');
        $document = $collection->document($userId)->snapshot();

        if ($document->exists()) {
            $data = $document->data();
            $this->firstName = $data['firstName'] ?? '';
            $this->secondName = $data['secondName'] ?? '';
            $this->email = $data['email'] ?? '';
            $this->phone = $data['phone'] ?? ''; // Load full phone number (e.g., "+254701234567")
            $this->selectedCountry = $data['country'] ?? 'KE'; // Load country ID
            $this->ventureName = $data['ventureName'] ?? '';
            $this->website = $data['website'] ?? '';
            $this->industry = $data['industry'] ?? '';
            $this->businessModel = $data['businessModel'] ?? '';
            $this->level = $data['level'] ?? '';
            $this->companySolution = $data['companySolution'] ?? '';
            $this->africanLed = $data['africanLed'] ?? '';
            $this->africanPercentage = $data['africanPercentage'] ?? '';
            $this->femaleFounder = $data['femaleFounder'] ?? '';
            $this->femalePercentage = $data['femalePercentage'] ?? '';
            $this->countryHeadquarters = $data['countryHeadquarters'] ?? 'Kenya';
            $this->city = $data['city'] ?? '';
            $this->date = $data['date'] ?? '';
            $this->lifecycle = $data['lifecycle'] ?? '';
            $this->makeMoney = $data['makeMoney'] ?? '';
            $this->generatingRevenue = $data['generatingRevenue'] ?? '';
            $this->amountRaising = $data['amountRaising'] ?? '';
            $this->monthsRunway = $data['monthsRunway'] ?? '';
            $this->externalFunding = $data['externalFunding'] ?? '';
            $this->selectedChallenges = $data['selectedChallenges'] ?? [];
            $this->documentUrl = $data['documentUrl'] ?? '';
            $this->relevantDocuments = $data['relevantDocuments'] ?? '';
            $this->referral = $data['referral'] ?? '';
            $this->details = $data['details'] ?? '';
            $this->mailing = $data['mailing'] ?? '';
            $this->pitchDeck_url = $data['documentUrl'] ?? '';
        }
    }

    public function getCountryOptions()
    {
        $path = storage_path('app/countries.json');
        if (file_exists($path)) {
            $json = file_get_contents($path);
            $countries = json_decode($json, true);
            return $countries ?? [];
        }
        Log::error('countries.json file not found at path: ' . $path);
        return [];
    }

    public function getCountryNamesOptions()
    {
        $path = storage_path('app/countrynames.json');
        if (file_exists($path)) {
            $json = file_get_contents($path);
            $countrynames = json_decode($json, true);
            return $countrynames ?? [];
        }
        Log::error('countrynames.json file not found at path: ' . $path);
        return [];
    }

    public function updatedSelectedCountry($value)
    {
        // Find the selected country's phone code
        $selectedCountry = collect($this->countryOptions)->firstWhere('id', $value);
        $newPhoneCode = $selectedCountry['phone_code'] ?? '+254';

        // Update the phone number with the new country code
        $this->updatePhoneCountryCode($newPhoneCode);
    }

    private function updatePhoneCountryCode($newPhoneCode)
    {
        // Extract the existing number (without the country code)
        $currentNumber = $this->phone ? preg_replace('/^\+\d{1,4}/', '', $this->phone) : '';
        
        // Combine the new country code with the existing number
        $this->phone = $newPhoneCode . $currentNumber;
    }

    public function submitForm()
    {
        $this->validate([
            'firstName' => 'required|string',
            'secondName' => 'required|string',
            'email' => 'required|email',
            'phone' => 'required',
            'selectedCountry' => 'required|string',
            'ventureName' => 'required|string',
            'website' => 'nullable|url',
            'industry' => 'required|string',
            'businessModel' => 'required|string',
            'level' => 'required|string',
            'companySolution' => 'required|string',
            'africanLed' => 'required|string',
            'africanPercentage' => 'required|numeric|min:0|max:100',
            'femaleFounder' => 'required|string',
            'femalePercentage' => 'required|numeric|min:0|max:100',
            'countryHeadquarters' => 'required|string',
            'city' => 'required|string',
            'date' => 'required|date|before_or_equal:today',
            'lifecycle' => 'required|string',
            'makeMoney' => 'required|string',
            'generatingRevenue' => 'required|string',
            'amountRaising' => 'required|numeric|min:0',
            'monthsRunway' => 'required|numeric|min:0|max:100',
            'externalFunding' => 'required|string',
            'selectedChallenges' => 'required|array',
            'pitchDeck_url' => 'nullable|url',
            'relevantDocuments' => 'nullable|string',
            'referral' => 'required|string',
            'details' => 'nullable|string',
            'mailing' => 'required|string',
        ]);
    
        $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
        $firestore = $factory->createFirestore();
        $collection = $firestore->database()->collection('InvesteeApplication');
    
        $userId = session('firebase_user');
        $timestamp = new \Google\Cloud\Core\Timestamp(new \DateTimeImmutable());
    
        // Check if the document already exists
        $existingDoc = $collection->document($userId)->snapshot();
        $existingData = $existingDoc->exists() ? $existingDoc->data() : [];
        $isUpdate = $existingDoc->exists();
    
        $data = [
            'userId' => $userId,
            'firstName' => $this->firstName,
            'secondName' => $this->secondName,
            'email' => $this->email,
            'phone' => $this->phone,
            'country' => $this->selectedCountry,
            'ventureName' => $this->ventureName,
            'website' => $this->website,
            'industry' => $this->industry,
            'businessModel' => $this->businessModel,
            'level' => $this->level,
            'companySolution' => $this->companySolution,
            'africanLed' => $this->africanLed,
            'africanPercentage' => (string)$this->africanPercentage,
            'femaleFounder' => $this->femaleFounder,
            'femalePercentage' => (string)$this->femalePercentage,
            'countryHeadquarters' => $this->countryHeadquarters,
            'city' => $this->city,
            'date' => $this->date,
            'lifecycle' => $this->lifecycle,
            'makeMoney' => $this->makeMoney,
            'generatingRevenue' => $this->generatingRevenue,
            'amountRaising' => (string)$this->amountRaising,
            'monthsRunway' => (string)$this->monthsRunway,
            'externalFunding' => $this->externalFunding,
            'selectedChallenges' => $this->selectedChallenges,
            'documentUrl' => $this->pitchDeck_url ?? '',
            'relevantDocuments' => $this->relevantDocuments,
            'referral' => $this->referral,
            'details' => $this->details,
            'mailing' => $this->mailing,
            'timestamp' => $timestamp,
            'verified' => $existingData['verified'] ?? false,
            'approved' => $existingData['approved'] ?? false,
        ];
    
        $collection->document($userId)->set($data, ['merge' => true]);
    
        // Send email to admin based on whether it’s an update or new submission
        try {
            $adminEmail = config('mail.admin.to');
            $ccEmails = config('mail.admin.cc');
    
            Log::info('Investee Application - Admin Email: ' . ($adminEmail ?? 'Not set'));
            Log::info('Investee Application - CC Emails: ' . json_encode($ccEmails ?? []));
    
            if (!$adminEmail) {
                throw new \Exception('Admin email is not configured in mail settings.');
            }
    
            if ($isUpdate) {
                Mail::to($adminEmail)
                    ->cc($ccEmails)
                    ->send(new InvesteeApplicationUpdated($data, $userId));
            } else {
                Mail::to($adminEmail)
                    ->cc($ccEmails)
                    ->send(new InvesteeApplicationSubmitted($data, $userId));
            }
        } catch (\Exception $e) {
            Log::error('Failed to send Investee application email: ' . $e->getMessage());
        }
    
        $this->success(
            title: $isUpdate ? 'Investee application updated successfully' : 'Investee application submitted successfully',
            position: 'toast-top toast-center',
            css: 'max-w-[90vw] w-auto',
            timeout: 4000
        );
        $this->loadExistingData();
    }

    public function render()
    {
        return view('livewire.more.for-investment');
    }
}