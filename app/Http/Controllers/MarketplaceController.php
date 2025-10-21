<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Kreait\Firebase\Factory;
use Google\Cloud\Firestore\FieldValue;
use Illuminate\Support\Facades\Log;

class MarketplaceController extends Controller
{
    public function store(Request $request)
    {
        Log::info('Marketplace store called with data: ' . json_encode($request->all()));
        Log::info('Request files: ' . json_encode($request->allFiles()));

        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'required|string',
                'amountInvested' => 'nullable|integer|min:0',
                'noOfInvestors' => 'nullable|integer|min:0',
                'companyAsk' => 'required|integer|min:0',
                'founder' => 'nullable|string|max:255',
                'businessType' => 'nullable|string|max:255',
                'website' => 'nullable|url',
                'location' => 'nullable|string|max:255',
                'financialForecast' => 'nullable|string',
                'operationsAndManagement' => 'nullable|string',
                'marketingAndSalesStrategy' => 'nullable|string',
                'financialStatements' => 'nullable|string',
                'portfolioManagement' => 'nullable|string',
                'researchAndDevelopement' => 'nullable|string',
                'riskManagement' => 'nullable|string',
                'exitStrategy' => 'nullable|string',
                'earnings' => 'nullable|integer|min:0',
                'imageUrl.*' => 'nullable|image|max:2048',
                'pitchDeck' => 'nullable|mimes:pdf|max:2048',
            ]);
            Log::info('Validation passed: ' . json_encode($validated));

            $credentialsPath = storage_path('firebase/firebase-credentials.json');
            if (!file_exists($credentialsPath)) {
                throw new \Exception('Firebase credentials not found at: ' . $credentialsPath);
            }
            $factory = (new Factory)->withServiceAccount($credentialsPath);
            $firestore = $factory->createFirestore();
            $storage = $factory->createStorage();
            $bucket = $storage->getBucket();
            Log::info('Firebase initialized with bucket: ' . $bucket->name());

            $userId = session('firebase_user');
            if (!$userId) {
                throw new \Exception('User not authenticated');
            }
            Log::info('User ID: ' . $userId);

            $investorDoc = $firestore->database()->collection('InvestorApplication')->document($userId)->snapshot();
            if (!$investorDoc->exists()) {
                throw new \Exception('InvestorApplication not found for user: ' . $userId);
            }
            $firstName = $investorDoc->get('firstName') ?? '';
            $secondName = $investorDoc->get('secondName') ?? '';
            $creatorName = trim("$firstName $secondName");
            $creatorEmail = $investorDoc->get('email') ?? 'unknown@example.com';
            Log::info("Creator name fetched: $creatorName, email: $creatorEmail");

            $data = [
                'amountInvested' => (int) ($request->amountInvested ?? 0),
                'careers' => null,
                'companyAsk' => (int) $request->companyAsk,
                'createdAt' => FieldValue::serverTimestamp(),
                'creator' => $userId,
                'creatorEmail' => $creatorEmail,
                'creatorName' => $creatorName,
                'description' => $request->description,
                'earnings' => (int) ($request->earnings ?? 0),
                'imageUrl' => [],
                'industry' => $investorDoc->get('industry') ?? 'Other',
                'investors' => [],
                'moreInfo' => [
                    'Business_type' => $request->businessType ?? '',
                    'Exit Strategy' => $request->exitStrategy ?? '',
                    'Financial Forecast' => $request->financialForecast ?? '',
                    'Financial Statements' => $request->financialStatements ?? '',
                    'Founder' => $request->founder ?? '',
                    'Location' => $request->location ?? '',
                    'Marketing and Sales Strategy' => $request->marketingAndSalesStrategy ?? '',
                    'Operations and Management' => $request->operationsAndManagement ?? '',
                    'Portfolio Management' => $request->portfolioManagement ?? '',
                    'Research and Development' => $request->researchAndDevelopement ?? '',
                    'Risk Management' => $request->riskManagement ?? '',
                    'Website' => $request->website ?? '',
                ],
                'noOfInvestors' => (int) ($request->noOfInvestors ?? 0),
                'pitchDeck' => null,
                'revenue' => 0,
                'reviews' => [],
                'showedDisinterest' => 0,
                'showedDisinterestUsers' => null,
                'showedInterest' => 0,
                'showedInterestUsers' => [],
                'subscriptionExpiry' => now()->addMonth()->toDateTime(),
                'title' => $request->title,
                'verified' => false,
            ];

            if ($request->hasFile('imageUrl')) {
                $imageUrls = [];
                foreach ($request->file('imageUrl') as $index => $image) {
                    if (!$image->isValid()) {
                        Log::warning('Invalid image at index ' . $index . ': ' . $image->getErrorMessage());
                        continue;
                    }
                    $tempPath = $image->getPathname();
                    Log::info('Image temp path: ' . $tempPath . ', Name: ' . $image->getClientOriginalName() . ', Size: ' . $image->getSize());
                    $firebasePath = 'images/' . $userId . '_' . time() . '_' . $index . '.' . $image->getClientOriginalExtension();
                    $uploadedFile = $bucket->upload(fopen($tempPath, 'r'), [
                        'name' => $firebasePath,
                        'predefinedAcl' => 'publicRead',
                    ]);
                    $imageUrls[] = $uploadedFile->signedUrl(new \DateTime('+10 years'));
                    Log::info('Image uploaded: ' . end($imageUrls));
                }
                $data['imageUrl'] = $imageUrls;
            } else {
                Log::info('No image files uploaded');
            }

            if ($request->hasFile('pitchDeck')) {
                $pitchDeck = $request->file('pitchDeck');
                if (!$pitchDeck->isValid()) {
                    throw new \Exception('Invalid pitch deck: ' . $pitchDeck->getErrorMessage());
                }
                $tempPath = $pitchDeck->getPathname();
                Log::info('Pitch deck temp path: ' . $tempPath . ', Name: ' . $pitchDeck->getClientOriginalName() . ', Size: ' . $pitchDeck->getSize());
                $firebasePath = 'documents/' . $userId . '_pitch_' . time() . '.pdf';
                $uploadedFile = $bucket->upload(fopen($tempPath, 'r'), [
                    'name' => $firebasePath,
                    'predefinedAcl' => 'publicRead',
                ]);
                $data['pitchDeck'] = $uploadedFile->signedUrl(new \DateTime('+10 years'));
                Log::info('Pitch deck uploaded: ' . $data['pitchDeck']);
            } else {
                Log::info('No pitch deck uploaded');
            }

            Log::info('Final data to store: ' . json_encode($data));
            $firestore->database()->collection('Marketplace')->document($userId)->set($data);
            Log::info('Data stored in Firestore for user: ' . $userId);

            return redirect()->route('marketplace')->with('success', 'Marketplace item created successfully.');
        } catch (\Exception $e) {
            Log::error('Error in marketplace store: ' . $e->getMessage() . "\nStack: " . $e->getTraceAsString());
            return redirect()->back()->with('error', 'Failed to create marketplace item: ' . $e->getMessage())->withInput();
        }
    }
}