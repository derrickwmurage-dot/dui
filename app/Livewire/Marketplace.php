<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\Attributes\Url;
use Kreait\Firebase\Factory;
use Google\Cloud\Firestore\FieldValue;
use Illuminate\Support\Facades\Log;
use Mary\Traits\Toast;
use Illuminate\Support\Facades\Http;
use App\Mail\InvestmentRequest;
use Illuminate\Support\Facades\Mail;
use App\Mail\MarketplacePaymentConfirmation as PaymentConfirmation;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Mail\MarketplaceItemCreated;

class Marketplace extends Component
{
    use Toast;

    public $marketplaceData = [];
    public $modalData;
    public $modalInvestData;
    public bool $marketplaceModal = false;
    public bool $investModal = false;
    public bool $paymentModal = false;
    public float $companyValuation = 0.0;
    public float $equityAmount = 0.0;
    public float $equityPercentage = 0.0;
    public string $valueAddition = '';
    public string $confirmationMessage = '';
    public bool $isInvestor = false;
    public bool $isApproved = false;
    public array $investmentDetails = [];
    public bool $createMarketplaceItemModal = false;
    public bool $subscriptionExpiredModal = false;
    public array $form = [
        'title' => '',
        'description' => '',
        'amountInvested' => '',
        'noOfInvestors' => '',
        'companyAsk' => '',
        'founder' => '',
        'businessType' => '',
        'website' => '',
        'location' => '',
        'financialForecast' => '',
        'operationsAndManagement' => '',
        'marketingAndSalesStrategy' => '',
        'financialStatements' => '',
        'portfolioManagement' => '',
        'researchAndDevelopement' => '',
        'riskManagement' => '',
        'exitStrategy' => '',
        'companyValuation' => '', // Now represents average revenue per annum
        'earnings' => '',
    ];
    public float $calculatedCompanyValuation = 0.0; // New property for calculated valuation
    public string $pitchDeckUrl;
    public array $imageUrls;
    public bool $successModal = false;
    public array $successDetails = [];
    public bool $editMarketplaceItemModal = false;
    public string $successMessage = '';
    public array $editForm = [];
    public bool $isSubmitting = false;

    #[Url(history: true)]
    public string $searchTerm = '';

    public float $maxEquity = 0.0;
    public float $investmentAmount = 0.0;

    protected $listeners = ['paymentSuccess' => 'showSuccessModal'];

    public function mount()
    {
        $this->fetchMarketplaceData();
        $this->successMessage = session('success', '');
        if (session('success')) {
            $this->success(title: session('success'), position: 'toast-top toast-center', css: 'max-w-[90vw] w-auto', timeout: 4000);
        }
        if (session('error')) {
            $this->error(title: session('error'), position: 'toast-top toast-center', css: 'max-w-[90vw] w-auto', timeout: 4000);
        }
        if (session()->has('successDetails')) {
            $this->successDetails = session('successDetails');
            $this->successModal = true;
            Log::info('Success details found in session: ' . json_encode($this->successDetails));
        } else {
            Log::info('No success details found in session.');
        }
    }

    public function fetchMarketplaceData()
    {
        try {
            $userId = session('firebase_user');
            if (!$userId) {
                throw new \Exception('User not authenticated');
            }

            $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            $firestore = $factory->createFirestore();
            $collection = $firestore->database()->collection('Marketplace');

            $documents = $collection->where('verified', '=', true)->limit(100)->documents();

            $this->marketplaceData = [];
            $currentDate = Carbon::now();

            foreach ($documents as $document) {
                $data = $document->data();
                $data['id'] = $document->id();

                $showItem = true;

                if (isset($data['subscriptionExpiry']) && $data['subscriptionExpiry'] instanceof \Google\Cloud\Core\Timestamp) {
                    $subscriptionExpiry = $data['subscriptionExpiry']->get();
                    $data['subscriptionExpiry'] = $subscriptionExpiry->format('Y-m-d H:i:s');
                    if ($subscriptionExpiry < $currentDate) {
                        $showItem = false;
                        continue;
                    }
                }

                if (isset($data['investors']) && is_array($data['investors'])) {
                    foreach ($data['investors'] as $investor) {
                        if (isset($investor['investorId']) && 
                            $investor['investorId'] === $userId && 
                            isset($investor['investmentComplete']) && 
                            $investor['investmentComplete'] === true) {
                            $showItem = false;
                            break;
                        }
                    }
                }

                if (!$showItem) {
                    continue;
                }

                if (isset($data['createdAt']) && $data['createdAt'] instanceof \Google\Cloud\Core\Timestamp) {
                    $data['createdAt'] = $data['createdAt']->get()->format('Y-m-d H:i:s');
                }

                // Apply search filter
                if (!empty($this->searchTerm)) {
                    $searchTerm = strtolower(trim($this->searchTerm));
                    $searchFields = [
                        strtolower($data['title'] ?? ''),
                        strtolower($data['description'] ?? ''),
                        strtolower($data['moreInfo']['Business_type'] ?? ''),
                        strtolower($data['moreInfo']['Founder'] ?? ''),
                        strtolower($data['moreInfo']['Location'] ?? ''),
                        strtolower($data['moreInfo']['companyDescription'] ?? ''),
                        strtolower($data['moreInfo']['financialForecast'] ?? ''),
                        strtolower($data['moreInfo']['operationsAndManagement'] ?? ''),
                        strtolower($data['moreInfo']['marketingAndSalesStrategy'] ?? ''),
                    ];

                    $matchFound = false;
                    foreach ($searchFields as $field) {
                        if (str_contains($field, $searchTerm)) {
                            $matchFound = true;
                            break;
                        }
                    }

                    if (!$matchFound) {
                        continue;
                    }
                }

                $this->marketplaceData[] = $data;
            }

            if (!empty($this->marketplaceData)) {
                usort($this->marketplaceData, function ($a, $b) use ($userId) {
                    $aIsCreator = isset($a['creator']) && $a['creator'] === $userId;
                    $bIsCreator = isset($b['creator']) && $b['creator'] === $userId;

                    if ($aIsCreator && !$bIsCreator) return -1;
                    if (!$aIsCreator && $bIsCreator) return 1;
                    return 0;
                });
            }

            Log::info('fetchMarketplaceData executed successfully. Items: ' . count($this->marketplaceData));
        } catch (\Exception $e) {
            Log::error('Error fetching marketplace data: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            $this->marketplaceData = [];
        }
    }

    public function openCreateMarketplaceItemModal()
    {
        try {
            $userId = session('firebase_user');
            if (!$userId) {
                $this->error(title: 'Please log in again.', position: 'toast-top toast-center', css: 'max-w-[90vw] w-auto', timeout: 4000);
                return;
            }

            $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            $firestore = $factory->createFirestore();
            $db = $firestore->database();

            $profileDoc = $db->collection('Profile')->document($userId)->snapshot();
            if (!$profileDoc->exists() || !$this->isProfileComplete($profileDoc->data())) {
                $this->error(title: 'Please complete your profile first', position: 'toast-top toast-center', css: 'max-w-[90vw] w-auto', timeout: 4000);
                return;
            }

            $investeeDoc = $db->collection('InvesteeApplication')->document($userId)->snapshot();
            if (!$investeeDoc->exists()) {
                $this->error(title: 'Please complete the investee application form', position: 'toast-top toast-center', css: 'max-w-[90vw] w-auto', timeout: 4000);
                return;
            }

            $marketplaceDoc = $db->collection('Marketplace')->document($userId)->snapshot();
            if ($marketplaceDoc->exists()) {
                $this->error(title: 'You have already created a marketplace item', position: 'toast-top toast-center', css: 'max-w-[90vw] w-auto', timeout: 4000);
                return;
            }

            $subscriptionDoc = $db->collection('Subscriptions')->document($userId)->snapshot();
            if ($subscriptionDoc->exists()) {
                $subscriptionData = $subscriptionDoc->data();
                if (isset($subscriptionData['subscriptionExpiry']) && $subscriptionData['subscriptionExpiry'] instanceof \Google\Cloud\Core\Timestamp) {
                    $expiryDate = $subscriptionData['subscriptionExpiry']->get();
                    if ($expiryDate->isPast()) {
                        $this->subscriptionExpiredModal = true;
                        return;
                    }
                }
            }

            $this->resetForm();
            $this->createMarketplaceItemModal = true;
        } catch (\Exception $e) {
            Log::error('Error in openCreateMarketplaceItemModal: ' . $e->getMessage());
            $this->error(title: 'An error occurred. Please try again later.', position: 'toast-top toast-center', css: 'max-w-[90vw] w-auto', timeout: 4000);
        }
    }

    public function closeSubscriptionExpiredModal()
    {
        $this->subscriptionExpiredModal = false;
    }

    public function renewSubscription()
    {
        $userId = session('firebase_user');
        $amount = 150 * 100; // Amount in kobo

        $paymentData = [
            'email' => auth()->user()->email,
            'amount' => $amount,
            'reference' => 'renew_subscription_' . time(),
            'callback_url' => route('subscription.callback'),
            'metadata' => [
                'user_id' => $userId,
                'customizations' => [
                    'title' => 'Renew Subscription',
                    'description' => 'Payment for renewing subscription',
                ],
            ],
        ];

        try {
            $response = Http::withToken(config('services.paystack.secret'))
                ->post('https://api.paystack.co/transaction/initialize', $paymentData);

            if ($response->successful()) {
                $paymentLink = $response->json()['data']['authorization_url'];
                return redirect()->away($paymentLink);
            } else {
                $this->error(title: 'Failed to initiate payment. Please try again.', position: 'toast-top toast-center', css: 'max-w-[90vw] w-auto', timeout: 4000);
            }
        } catch (\Exception $e) {
            Log::error('Paystack payment initiation error: ' . $e->getMessage());
            $this->error(title: 'An error occurred while initiating payment. Please try again.', position: 'toast-top toast-center', css: 'max-w-[90vw] w-auto', timeout: 4000);
        }
    }

    public function calculateCompanyValuation()
    {
        $revenue = floatval($this->form['companyValuation'] ?? 0);
        $earnings = floatval($this->form['earnings'] ?? 0);
        $earningsMultiplier = 7;
        $revenueMultiplier = 1;
        $this->calculatedCompanyValuation = ($earnings * $earningsMultiplier) + ($revenue * $revenueMultiplier);
    }

    public function createMarketplace()
    {
        $this->isSubmitting = true;
    
        try {
            $this->validate([
                'form.title' => 'required|string',
                'form.description' => 'required|string',
                'form.amountInvested' => 'nullable|numeric|min:0',
                'form.companyAsk' => 'required|numeric|min:0',
                'form.founder' => 'nullable|string',
                'form.businessType' => 'nullable|string',
                'form.website' => 'nullable|url',
                'form.location' => 'nullable|string',
                'form.financialForecast' => 'nullable|string',
                'form.operationsAndManagement' => 'nullable|string',
                'form.marketingAndSalesStrategy' => 'nullable|string',
                'form.financialStatements' => 'nullable|string',
                'form.portfolioManagement' => 'nullable|string',
                'form.researchAndDevelopement' => 'nullable|string',
                'form.riskManagement' => 'nullable|string',
                'form.exitStrategy' => 'nullable|string',
                'form.earnings' => 'nullable|numeric|min:0',
                'pitchDeckUrl' => 'nullable|url',
                'imageUrls' => 'nullable|array',
                'imageUrls.*' => 'url',
            ]);
    
            $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            $firestore = $factory->createFirestore();
    
            $userId = session('firebase_user');
            if (!$userId) {
                throw new \Exception('User not authenticated');
            }
    
            $investorDoc = $firestore->database()->collection('InvestorApplication')->document($userId)->snapshot();
            if (!$investorDoc->exists()) {
                throw new \Exception('InvestorApplication not found for user: ' . $userId);
            }
            $investorData = $investorDoc->data();
            $creatorEmail = $investorData['email'] ?? 'unknown@example.com';
            $creatorName = trim(($investorData['firstName'] ?? '') . ' ' . ($investorData['secondName'] ?? ''));
    
            $this->calculateCompanyValuation();
    
            $data = [
                'amountInvested' => floatval($this->form['amountInvested'] ?? 0),
                'careers' => null,
                'companyAsk' => floatval($this->form['companyAsk']),
                'createdAt' => FieldValue::serverTimestamp(),
                'creator' => $userId,
                'creatorEmail' => $creatorEmail,
                'creatorName' => $creatorName,
                'description' => $this->form['description'],
                'earnings' => floatval($this->form['earnings'] ?? 0),
                'imageUrl' => $this->imageUrls ?? [],
                'industry' => $investorData['industry'] ?? 'Other',
                'investors' => [],
                'moreInfo' => [],
                'noOfInvestors' => 0,
                'pitchDeck' => $this->pitchDeckUrl ?? null,
                'revenue' => floatval($this->form['companyValuation'] ?? 0),
                'reviews' => [],
                'showedDisinterest' => 0,
                'showedDisinterestUsers' => null,
                'showedInterest' => 0,
                'showedInterestUsers' => [],
                'subscriptionExpiry' => new \Google\Cloud\Core\Timestamp(now()->addMonth()),
                'title' => $this->form['title'],
                'verified' => false,
            ];
    
            $optionalFields = [
                'businessType' => 'Business_type',
                'exitStrategy' => 'Exit Strategy',
                'financialForecast' => 'Financial Forecast',
                'financialStatements' => 'Financial Statements',
                'founder' => 'Founder',
                'location' => 'Location',
                'marketingAndSalesStrategy' => 'Marketing and Sales Strategy',
                'operationsAndManagement' => 'Operations and Management',
                'portfolioManagement' => 'Portfolio Management',
                'researchAndDevelopement' => 'Research and Development',
                'riskManagement' => 'Risk Management',
                'website' => 'Website',
            ];
            foreach ($optionalFields as $formKey => $firestoreKey) {
                if (!empty($this->form[$formKey])) {
                    $data['moreInfo'][$firestoreKey] = $this->form[$formKey];
                }
            }
    
            $collection = $firestore->database()->collection('Marketplace');
            $docRef = $collection->document($userId);
            $docRef->set($data);
    
            // Send email to admin after successful creation
            try {
                $adminEmail = config('mail.admin.to');
                $ccEmails = config('mail.admin.cc');
    
                // Log the values for debugging
                Log::info('Admin Email: ' . ($adminEmail ?? 'Not set'));
                Log::info('CC Emails: ' . json_encode($ccEmails ?? []));
    
                if (!$adminEmail) {
                    throw new \Exception('Admin email is not configured in mail settings.');
                }
    
                Mail::to($adminEmail)
                    ->cc($ccEmails)
                    ->send(new MarketplaceItemCreated($data, $creatorName, $creatorEmail));
            } catch (\Exception $e) {
                Log::error('Failed to send marketplace item email: ' . $e->getMessage());
            }
    
            $this->success(title: 'Marketplace item created successfully.', position: 'toast-top toast-center', css: 'max-w-[90vw] w-auto', timeout: 4000);
            $this->closeCreateMarketplaceItemModal();
            $this->fetchMarketplaceData();
            return redirect()->route('marketplace');
        } catch (\Exception $e) {
            Log::error('Error in createMarketplace: ' . $e->getMessage() . "\nStack: " . $e->getTraceAsString());
            $this->error(title: 'Failed to create marketplace item: ' . $e->getMessage(), position: 'toast-top toast-center', css: 'max-w-[90vw] w-auto', timeout: 4000);
        } finally {
            $this->isSubmitting = false;
        }
    }

    public function removeImage($index)
    {
        if (isset($this->imageUrls[$index])) {
            unset($this->imageUrls[$index]);
            $this->imageUrls = array_values($this->imageUrls);
            $this->success(title: 'Image removed successfully', position: 'toast-top toast-center', css: 'max-w-[90vw] w-auto', timeout: 4000);
        }
    }

    private function resetForm()
    {
        $this->form = array_fill_keys(array_keys($this->form), '');
        $this->pitchDeckUrl = '';
        $this->imageUrls = [];
        $this->calculatedCompanyValuation = 0.0;
    }

    private function isProfileComplete($profileData)
    {
        return isset($profileData['completion']['completion']) && $profileData['completion']['completion'] >= 95;
    }

    public function openMarketplaceModal($index)
    {
        $this->modalData = $this->marketplaceData[$index];
        $this->calculateCompanyValuationAndEquity();
        $this->checkInvestorStatus($index);
        $this->marketplaceModal = true;

        if (!isset($this->modalData['companyAsk'])) {
            Log::warning('companyAsk undefined for marketplace item', [
                'index' => $index,
                'item' => $this->modalData,
            ]);
        }
    }

    public function calculateCompanyValuationAndEquity()
    {
        $earnings = floatval($this->modalData['earnings'] ?? 0);
        $earningsMultiplier = 7;
        $this->companyValuation = $earnings * $earningsMultiplier; // Remove revenue for consistency
        
        // Handle missing companyAsk gracefully
        $this->investmentAmount = floatval($this->modalData['companyAsk'] ?? 0);
        $this->equityPercentage = ($this->companyValuation != 0) 
            ? ($this->investmentAmount / $this->companyValuation) * 100 
            : 0.0;

        $this->equityAmount = $this->equityPercentage / 100; // Optional, for compatibility

        Log::info('Calculated company valuation and equity', [
            'companyValuation' => $this->companyValuation,
            'investmentAmount' => $this->investmentAmount,
            'equityPercentage' => $this->equityPercentage,
            'earnings' => $earnings,
            'companyAsk' => $this->modalData['companyAsk'] ?? 'undefined',
        ]);
    }

    public function toggleInterest($index, $type)
    {
        $userId = session('firebase_user');
        $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
        $firestore = $factory->createFirestore();

        $investorDoc = $firestore->database()->collection('InvestorApplication')->document($userId)->snapshot();
        if (!$investorDoc->exists()) {
            $this->error(title: 'Please complete the investor application form first', position: 'toast-top toast-center', css: 'max-w-[90vw] w-auto', timeout: 4000);
            return;
        }

        $docRef = $firestore->database()
            ->collection('Marketplace')
            ->document($this->marketplaceData[$index]['id']);

        $oppositeType = $type === 'interest' ? 'disinterest' : 'interest';
        $oppositeArray = 'showed' . ucfirst($oppositeType) . 'Users';
        if (isset($this->marketplaceData[$index][$oppositeArray]) && 
            in_array($userId, $this->marketplaceData[$index][$oppositeArray])) {
            $docRef->update([
                ['path' => $oppositeArray, 'value' => FieldValue::arrayRemove([$userId])]
            ]);
            $this->marketplaceData[$index][$oppositeArray] = array_diff($this->marketplaceData[$index][$oppositeArray], [$userId]);
        }

        $currentArray = 'showed' . ucfirst($type) . 'Users';
        if (!isset($this->marketplaceData[$index][$currentArray]) || 
            !in_array($userId, $this->marketplaceData[$index][$currentArray])) {
            $docRef->update([
                ['path' => $currentArray, 'value' => FieldValue::arrayUnion([$userId])]
            ]);
            $this->marketplaceData[$index][$currentArray][] = $userId;
        } else {
            $docRef->update([
                ['path' => $currentArray, 'value' => FieldValue::arrayRemove([$userId])]
            ]);
            $this->marketplaceData[$index][$currentArray] = array_diff($this->marketplaceData[$index][$currentArray], [$userId]);
        }
    }

    public function openComments($index)
    {
        $documentId = $this->marketplaceData[$index]['id'];
        $this->redirect(route('marketplace.comments', ['id' => $documentId]));
    }

    public function openInvestModal($index)
    {
        $userId = session('firebase_user');
        if (!$userId) {
            $this->error(title: 'User not authenticated', position: 'toast-top toast-center', css: 'max-w-[90vw] w-auto', timeout: 4000);
            return;
        }
    
        $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
        $firestore = $factory->createFirestore();
        $database = $firestore->database();
    
        $kycQuery = $database->collection('KYC')
            ->where('userId', '=', $userId)
            ->limit(1)
            ->documents();
        $kycDoc = null;
        foreach ($kycQuery as $doc) {
            $kycDoc = $doc;
            break;
        }
    
        if (!$kycDoc || !$kycDoc->exists() || !$kycDoc->data()['approved']) {
            $this->error(title: 'Please complete and get your KYC approved before investing.', position: 'toast-top toast-center', css: 'max-w-[90vw] w-auto', timeout: 4000);
            return;
        }
    
        $investorDoc = $database->collection('InvestorApplication')->document($userId)->snapshot();
        if (!$investorDoc->exists()) {
            $this->error(title: 'Please complete the investor application form first', position: 'toast-top toast-center', css: 'max-w-[90vw] w-auto', timeout: 4000);
            return;
        }
    
        if (!isset($this->marketplaceData[$index])) {
            $this->error(title: 'Invalid marketplace item', position: 'toast-top toast-center', css: 'max-w-[90vw] w-auto', timeout: 4000);
            return;
        }
    
        $this->modalInvestData = $this->marketplaceData[$index];
        
        // Ensure companyAsk exists, default to 0 if missing (e.g., unverified item)
        if (!isset($this->modalInvestData['companyAsk'])) {
            $this->modalInvestData['companyAsk'] = 0;
            Log::warning('companyAsk undefined for invest modal item', [
                'index' => $index,
                'item' => $this->modalInvestData,
            ]);
        }
        
        $this->companyValuation = floatval($this->modalInvestData['earnings']) * 7;
        $this->maxEquity = ($this->companyValuation != 0) 
            ? (floatval($this->modalInvestData['companyAsk']) / $this->companyValuation) * 100 
            : 0.0;
    
        $this->equityPercentage = 0.0;
        $this->investmentAmount = 0.0;
        $this->valueAddition = '';
    
        $this->investModal = true;

        Log::info('Opening invest modal', [
            'index' => $index,
            'earnings' => $this->modalInvestData['earnings'],
            'companyAsk' => $this->modalInvestData['companyAsk'],
            'companyValuation' => $this->companyValuation,
            'maxEquity' => $this->maxEquity,
            'maxEquityRounded' => round($this->maxEquity, 4),
        ]);
    }
    
    public function updatedEquityPercentage()
    {
        $calculatedAmount = ($this->equityPercentage / 100) * $this->companyValuation;
        $companyAsk = floatval($this->modalInvestData['companyAsk']);
        
        // Tolerance of $0.01 to catch floating-point errors
        if (abs($calculatedAmount - $companyAsk) < 0.01) {
            $this->investmentAmount = $companyAsk;
        } else {
            $this->investmentAmount = min($calculatedAmount, $companyAsk);
        }
    }

    public function updatedInvestmentAmount()
    {
        if ($this->companyValuation != 0) {
            $this->equityPercentage = ($this->investmentAmount / $this->companyValuation) * 100;
        } else {
            $this->equityPercentage = 0.0;
        }
    }

    public function closeInvestModal()
    {
        $this->investModal = false;
    }

    public function submitInvestForm()
    {
        try {
            $this->validate([
                'valueAddition' => 'required|string',
                'equityPercentage' => 'required|numeric|min:0|max:' . $this->maxEquity,
            ]);

            $userId = session('firebase_user');
            if (!$userId) {
                throw new \Exception('User not authenticated');
            }

            $firebase = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            $firestore = $firebase->createFirestore();
            $marketplaceDoc = $firestore->database()->collection('Marketplace')->document($this->modalInvestData['id']);

            $investorDoc = $firestore->database()->collection('InvestorApplication')->document($userId)->snapshot();
            if (!$investorDoc->exists()) {
                throw new \Exception('InvestorApplication not found for user: ' . $userId);
            }

            $investorData = $investorDoc->data();
            $investorName = trim(($investorData['firstName'] ?? '') . ' ' . ($investorData['secondName'] ?? ''));

            $investmentDetails = [
                'age' => $investorData['age'] ?? '',
                'approved' => false,
                'companyName' => $investorData['company'] ?? '',
                'country' => $investorData['country'] ?? '',
                'date' => now()->format('d-m-Y'),
                'equity' => floatval($this->equityPercentage),
                'extraOfferings' => $this->valueAddition,
                'investmentAmount' => floatval($this->investmentAmount),
                'investmentComplete' => false,
                'investorId' => $userId,
                'investorName' => $investorName,
            ];

            $marketplaceDoc->update([
                ['path' => 'investors', 'value' => FieldValue::arrayUnion([$investmentDetails])]
            ]);

            $this->investModal = false;
            $this->successMessage = 'Investment request submitted successfully.';
            $this->success(title: 'Investment request submitted successfully.', position: 'toast-top toast-center', css: 'max-w-[90vw] w-auto', timeout: 4000);
            $this->fetchMarketplaceData();
        } catch (\Exception $e) {
            Log::error('Error in submitInvestForm: ' . $e->getMessage() . "\nStack: " . $e->getTraceAsString());
            $this->error(title: 'Failed to submit investment: ' . $e->getMessage(), position: 'toast-top toast-center', css: 'max-w-[90vw] w-auto', timeout: 4000);
        }
    }

    public function checkInvestorStatus($index)
    {
        try {
            if (!isset($this->marketplaceData[$index])) {
                throw new \Exception('Invalid marketplace index');
            }
    
            $userId = session('firebase_user');
            if (!$userId) {
                throw new \Exception('User not authenticated');
            }
    
            $marketplaceItem = $this->marketplaceData[$index];
            $investorDetails = null;
            if (isset($marketplaceItem['investors']) && is_array($marketplaceItem['investors'])) {
                foreach ($marketplaceItem['investors'] as $investor) {
                    if ($investor['investorId'] === $userId) {
                        $investorDetails = $investor;
                        break;
                    }
                }
            }
    
            if (!$investorDetails) {
                throw new \Exception('Investor details not found in marketplace item');
            }
    
            $this->investmentDetails = [
                'investmentAmount' => floatval($investorDetails['investmentAmount']),
                'companyName' => $marketplaceItem['title'] ?? '',
                'equity' => floatval($investorDetails['equity']),
                'extraOfferings' => $investorDetails['extraOfferings']
            ];
    
            $this->modalInvestData = [
                'id' => $marketplaceItem['id'],
                'title' => $marketplaceItem['title'],
                'creatorEmail' => $marketplaceItem['creatorEmail'],
                'creatorName' => $marketplaceItem['creatorName']
            ];
    
            Log::info('Investor status checked', [
                'index' => $index,
                'investmentAmount' => $investorDetails['investmentAmount'],
                'equity' => $investorDetails['equity'],
                'calculated_investmentDetails' => $this->investmentDetails
            ]);
        } catch (\Exception $e) {
            Log::error('Check investor status error:', [
                'error' => $e->getMessage(),
                'index' => $index
            ]);
        }
    }

    public function approvedInvestorAction($index)
    {
        $userId = session('firebase_user');
        $marketplaceItem = $this->marketplaceData[$index];
    
        $isApproved = false;
        if (isset($marketplaceItem['investors']) && is_array($marketplaceItem['investors'])) {
            foreach ($marketplaceItem['investors'] as $investor) {
                if ($investor['investorId'] === $userId && $investor['approved']) {
                    $isApproved = true;
                    break;
                }
            }
        }
    
        if ($isApproved) {
            $this->checkInvestorStatus($index);
            $this->paymentModal = true;
            Log::info('Approved investor action', [
                'index' => $index,
                'investmentDetails' => $this->investmentDetails
            ]);
        } else {
            $this->error(title: 'Your investment request is still pending approval', position: 'toast-top toast-center', css: 'max-w-[90vw] w-auto', timeout: 4000);
        }
    }

    public function closePaymentModal()
    {
        $this->paymentModal = false;
    }

    public function proceedToPayment()
    {
        try {
            $userId = session('firebase_user');
            if (!$userId) {
                throw new \Exception('User not authenticated');
            }
    
            if (empty($this->modalInvestData) || empty($this->investmentDetails)) {
                throw new \Exception('Investment data is missing');
            }
    
            $firebase = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            $auth = $firebase->createAuth();
            $user = $auth->getUser($userId);
            $userEmail = $user->email;
    
            if (!is_numeric($this->investmentDetails['investmentAmount']) || $this->investmentDetails['investmentAmount'] <= 0) {
                throw new \Exception('Invalid investment amount');
            }
    
            $paymentData = [
                'email' => $userEmail,
                'amount' => intval($this->investmentDetails['investmentAmount'] * 100),
                'reference' => uniqid('tx_'),
                'callback_url' => route('marketplace.callback'),
                'metadata' => [
                    'user_id' => $userId,
                    'companyName' => $this->investmentDetails['companyName'],
                    'investmentDetails' => $this->investmentDetails,
                    'modalInvestData' => $this->modalInvestData,
                    'customizations' => [
                        'title' => 'Investment Payment',
                        'description' => 'Payment for investment in marketplace item',
                    ],
                ],
            ];
    
            $response = Http::withToken(config('services.paystack.secret'))
                ->post('https://api.paystack.co/transaction/initialize', $paymentData);
    
            if ($response->successful()) {
                $paymentLink = $response->json()['data']['authorization_url'];
                Log::info('Payment initiated', [
                    'investmentAmount' => $this->investmentDetails['investmentAmount'],
                    'equity' => $this->investmentDetails['equity'],
                    'paymentData' => $paymentData
                ]);
                return redirect()->away($paymentLink);
            } else {
                throw new \Exception('Failed to initiate payment');
            }
        } catch (\Exception $e) {
            Log::error('Proceed to payment error:', [
                'error' => $e->getMessage(),
                'investmentDetails' => $this->investmentDetails
            ]);
            $this->error(title: 'Failed to proceed to payment. Please try again.', position: 'toast-top toast-center', css: 'max-w-[90vw] w-auto', timeout: 4000);
        }
    }

    public function handleCallback(Request $request)
    {
        $reference = $request->query('reference');
        $userId = session('firebase_user');

        try {
            $response = Http::withToken(config('services.paystack.secret'))
                ->get("https://api.paystack.co/transaction/verify/{$reference}");

            $verificationData = $response->json();

            if (!$response->successful() || 
                !isset($verificationData['data']['status']) || 
                $verificationData['data']['status'] !== 'success') {
                throw new \Exception('Payment verification failed');
            }

            $amount = floatval($verificationData['data']['amount'] / 100);
            $currency = $verificationData['data']['currency'];
            $customerEmail = $verificationData['data']['customer']['email'];
            $customerName = $verificationData['data']['customer']['first_name'] . ' ' . $verificationData['data']['customer']['last_name'];

            $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            $firestore = $factory->createFirestore();

            $paymentRecord = [
                'amount' => $amount,
                'createdAt' => now()->toIso8601String(),
                'currency' => $currency,
                'customerEmail' => $customerEmail,
                'customerName' => $customerName,
                'failReason' => '',
                'paymentDetails' => 'Payment for investment in marketplace item',
                'refId' => $reference,
                'userId' => $userId,
                'approved' => true,
            ];

            $firestore->database()
                ->collection('PaystackPayments')
                ->document($reference)
                ->set($paymentRecord);

            $marketplaceDocRef = $firestore->database()->collection('Marketplace')->document($this->modalInvestData['id']);
            $marketplaceDocRef->update([
                ['path' => 'investors', 'value' => FieldValue::arrayUnion([[
                    'investorId' => $userId,
                    'investmentComplete' => true
                ]])]
            ]);

            $investorEmail = $customerEmail;
            $investorName = $customerName;
            $ownerEmail = $this->modalInvestData['creatorEmail'];
            $ownerName = $this->modalInvestData['creatorName'];
            $marketplaceItemName = $this->modalInvestData['title'];

            $investmentDetails = [
                'ownerName' => $ownerName,
                'marketplaceItemName' => $marketplaceItemName,
                'investorName' => $investorName,
                'investmentAmount' => $amount,
                'equity' => floatval($this->investmentDetails['equity']),
                'extraOfferings' => $this->investmentDetails['extraOfferings'],
            ];

            Mail::to($investorEmail)
            ->bcc(config('mail.admin.to'))
            ->send(new PaymentConfirmation($investmentDetails));

            Mail::to($ownerEmail)
            ->bcc(config('mail.admin.to'))
            ->send(new InvestmentRequest($investmentDetails));

            $this->successDetails = $investmentDetails;
            $this->successModal = true;
        } catch (\Exception $e) {
            Log::error('Payment callback error: ' . $e->getMessage());
            return redirect()->route('marketplace')
                ->with('error', 'Payment verification failed. Please try again.');
        }
    }

    public function closeSuccessModal()
    {
        $this->successModal = false;
    }

    public function closeCreateMarketplaceItemModal()
    {
        $this->createMarketplaceItemModal = false;
    }

    public function redirectToInvestors($index)
    {
        $marketplaceItemId = $this->marketplaceData[$index]['id'];
        return redirect()->route('marketplace.investors', ['id' => $marketplaceItemId]);
    }

    public function openEditMarketplaceItemModal($index)
    {
        $this->editForm = $this->marketplaceData[$index];
        $this->editForm['id'] = $this->marketplaceData[$index]['id'];
        $this->editForm['moreInfo'] = $this->marketplaceData[$index]['moreInfo'] ?? [];
        $this->editForm['imageUrl'] = $this->marketplaceData[$index]['imageUrl'] ?? [];
        $this->editForm['newImageUrls'] = [];
        $this->editForm['pitchDeck'] = $this->marketplaceData[$index]['pitchDeck'] ?? '';
        $this->editMarketplaceItemModal = true;
        Log::info('Edit modal opened', ['editForm' => $this->editForm]);
    }

    public function removeEditImage($index)
    {
        if (isset($this->editForm['imageUrl'][$index])) {
            unset($this->editForm['imageUrl'][$index]);
            $this->editForm['imageUrl'] = array_values($this->editForm['imageUrl']);
            $this->success(title: 'Image removed successfully', position: 'toast-top toast-center', css: 'max-w-[90vw] w-auto', timeout: 4000);
        }
    }

    public function updatedFormCompanyValuation()
    {
        $this->calculateCompanyValuation();
    }

    public function updatedFormEarnings()
    {
        $this->calculateCompanyValuation();
    }
    public function addNewImageUrl($url)
    {
        $this->editForm['newImageUrls'][] = $url;
        // Merge newImageUrls into imageUrl for preview
        $this->editForm['imageUrl'] = array_merge($this->editForm['imageUrl'], $this->editForm['newImageUrls']);
    }

    public function addImageUrl($url)
    {
        $this->imageUrls[] = $url;
    }

    public function updateMarketplace()
    {
        try {
            $this->validate([
                'editForm.title' => 'required|string',
                'editForm.description' => 'required|string',
                'editForm.companyAsk' => 'required|numeric|min:0',
                'editForm.amountInvested' => 'nullable|numeric|min:0',
                'editForm.earnings' => 'nullable|numeric|min:0',
                'editForm.revenue' => 'nullable|numeric|min:0',
                'editForm.moreInfo.Address' => 'nullable|string',
                'editForm.moreInfo.Founder' => 'nullable|string',
                'editForm.moreInfo.Business_type' => 'nullable|string',
                'editForm.moreInfo.Website' => 'nullable|url',
                'editForm.moreInfo.Location' => 'nullable|string',
                'editForm.moreInfo.Financial Forecast' => 'nullable|string',
                'editForm.moreInfo.Operations and Management' => 'nullable|string',
                'editForm.moreInfo.Marketing and Sales Strategy' => 'nullable|string',
                'editForm.moreInfo.Financial Statements' => 'nullable|string',
                'editForm.moreInfo.Portfolio Management' => 'nullable|string',
                'editForm.moreInfo.Research and Development' => 'nullable|string',
                'editForm.moreInfo.Risk Management' => 'nullable|string',
                'editForm.moreInfo.Exit Strategy' => 'nullable|string',
                'editForm.imageUrl' => 'nullable|array',
                'editForm.imageUrl.*' => 'url',
                'editForm.pitchDeck' => 'nullable|url',
            ]);
    
            $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            $firestore = $factory->createFirestore();
            $collection = $firestore->database()->collection('Marketplace');
    
            // Merge new image URLs with existing ones, filtering out nulls
            $imageUrls = array_filter(
                array_merge(
                    $this->editForm['imageUrl'] ?? [],
                    $this->editForm['newImageUrls'] ?? []
                ),
                fn($url) => !empty($url) && filter_var($url, FILTER_VALIDATE_URL)
            );
    
            // Ensure pitchDeck is not in imageUrls
            if (!empty($this->editForm['pitchDeck'])) {
                $imageUrls = array_filter($imageUrls, fn($url) => $url !== $this->editForm['pitchDeck']);
            }
    
            $updateData = [
                'title' => $this->editForm['title'],
                'description' => $this->editForm['description'],
                'amountInvested' => floatval($this->editForm['amountInvested'] ?? 0),
                'companyAsk' => floatval($this->editForm['companyAsk']),
                'earnings' => floatval($this->editForm['earnings'] ?? 0),
                'revenue' => floatval($this->editForm['revenue'] ?? 0),
                'imageUrl' => array_values($imageUrls),
                'pitchDeck' => $this->editForm['pitchDeck'] ?? null,
                'moreInfo' => array_filter($this->editForm['moreInfo'], fn($value) => !empty($value)),
            ];
    
            $collection->document($this->editForm['id'])->set($updateData, ['merge' => true]);
    
            $this->editMarketplaceItemModal = false;
            $this->editForm['newImageUrls'] = [];
            $this->success(title: 'Marketplace item updated successfully.', position: 'toast-top toast-center', css: 'max-w-[90vw] w-auto', timeout: 4000);
            $this->fetchMarketplaceData();
        } catch (\Exception $e) {
            Log::error('Error in updateMarketplace: ' . $e->getMessage() . "\nStack: " . $e->getTraceAsString());
            $this->error(title: 'Failed to update marketplace item: ' . $e->getMessage(), position: 'toast-top toast-center', css: 'max-w-[90vw] w-auto', timeout: 4000);
        }
    }

    public function updatedSearchterm(){
        $this->fetchMarketplaceData();
    }

    public function render()
    {
        return view('livewire.marketplace', [
            'marketplaceData' => $this->marketplaceData,
            'companyValuation' => $this->companyValuation,
            'equityAmount' => $this->equityAmount,
        ]);
    }
}