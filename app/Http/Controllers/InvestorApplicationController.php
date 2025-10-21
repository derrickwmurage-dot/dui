<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Kreait\Firebase\Factory;
use Illuminate\Support\Facades\Log;

class InvestorApplicationController extends Controller
{
    protected $industries = [
        'agriTech' => 'Agri-Tech',
        'aiMl' => 'AI/ML',
        'arVr' => 'Augmented Reality/Virtual Reality',
        'blockchain' => 'Blockchain',
        'crypto' => 'Crypto',
        'developerTools' => 'Developer Tools',
        'biotech' => 'Biotech',
        'deepTech' => 'DeepTech',
        'd2cBrands' => 'Direct-To-Consumer-Brands',
        'eCommerce' => 'E-commerce',
        'education' => 'Education',
        'energy' => 'Energy',
        'enterpriseTech' => 'Enterprise and Tech',
        'fintech' => 'Fintech/Financial Services',
        'gamingEntertainment' => 'Gaming/Entertainment',
        'government' => 'Government',
        'hardware' => 'Hardware',
        'healthMedTech' => 'Health/MedTech/Healthcare',
        'lifeScience' => 'Life Science',
        'marketplace' => 'Marketplace',
        'mediaSocialMedia' => 'Media/Social Media',
        'mobilityTransportation' => 'Mobility/Transportation',
        'other' => 'Other',
    ];

    protected $fields = [
        'entrepreneur' => 'Entrepreneur',
        'investor' => 'Investor',
        'epso' => 'Entrepreneurial Support Organization',
        'developmentProfessional' => 'Development Professional',
        'government' => 'Government',
        'potentialInvestor' => 'Potential Investor',
        'noneOfTheAbove' => 'None of the above',
    ];

    public function store(Request $request)
    {
        Log::info('Investor application store called with data: ' . json_encode($request->all()));
        Log::info('Request files: ' . json_encode($request->allFiles()));

        try {
            $validated = $request->validate([
                'firstName' => 'required|string',
                'secondName' => 'required|string',
                'email' => 'required|email',
                'phone' => 'required|string',
                'country' => 'required|string',
                'company' => 'nullable|string',
                'website' => 'nullable|url',
                'industry' => 'nullable|string',
                'industryToInvest' => 'required|array',
                'level' => 'required|array',
                'amount' => 'required|array',
                'age' => 'required|numeric|min:0',
                'field' => 'required|string',
                'workTitle' => 'required|string',
                'referral' => 'required|string',
                'details' => 'nullable|string',
                'sourceOfFunds' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:10240', // 10MB
                'proofOfResidence' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:10240', // 10MB
            ]);
            Log::info('Validation passed: ' . json_encode($validated));

            $credentialsPath = storage_path('firebase/firebase-credentials.json');
            if (!file_exists($credentialsPath)) {
                throw new \Exception('Firebase credentials not found at: ' . $credentialsPath);
            }
            $factory = (new Factory)->withServiceAccount($credentialsPath);
            $storage = $factory->createStorage();
            $firestore = $factory->createFirestore();
            $bucket = $storage->getBucket();
            Log::info('Firebase initialized with bucket: ' . $bucket->name());

            $userId = session('firebase_user');
            if (!$userId) {
                throw new \Exception('User not authenticated');
            }
            Log::info('User ID: ' . $userId);

            // Handle file uploads
            $sourceOfFundsUrl = null;
            if ($request->hasFile('sourceOfFunds')) {
                $file = $request->file('sourceOfFunds');
                if ($file->isValid()) {
                    $filePath = "investor_applications/{$userId}_source_of_funds_" . time() . '.' . $file->getClientOriginalExtension();
                    $fileStream = fopen($file->getPathname(), 'r');
                    $uploadedFile = $bucket->upload($fileStream, [
                        'name' => $filePath,
                        'predefinedAcl' => 'publicRead',
                    ]);
                    $sourceOfFundsUrl = $uploadedFile->signedUrl(new \DateTime('+10 years'));
                    Log::info('Source of funds uploaded: ' . $sourceOfFundsUrl);
                }
            }

            $proofOfResidenceUrl = null;
            if ($request->hasFile('proofOfResidence')) {
                $file = $request->file('proofOfResidence');
                if ($file->isValid()) {
                    $filePath = "investor_applications/{$userId}_proof_of_residence_" . time() . '.' . $file->getClientOriginalExtension();
                    $fileStream = fopen($file->getPathname(), 'r');
                    $uploadedFile = $bucket->upload($fileStream, [
                        'name' => $filePath,
                        'predefinedAcl' => 'publicRead',
                    ]);
                    $proofOfResidenceUrl = $uploadedFile->signedUrl(new \DateTime('+10 years'));
                    Log::info('Proof of residence uploaded: ' . $proofOfResidenceUrl);
                }
            }

            // Map selected IDs to names
            $industryName = $this->industries[$request->industry] ?? $request->industry;
            $industryToInvestNames = array_map(fn($industry) => $this->industries[$industry] ?? $industry, $request->industryToInvest);
            $fieldName = $this->fields[$request->field] ?? $request->field;

            // Prepare data
            $data = [
                'userId' => $userId,
                'firstName' => $request->firstName,
                'secondName' => $request->secondName,
                'email' => $request->email,
                'phone' => $request->phone,
                'country' => $request->country,
                'company' => $request->company,
                'website' => $request->website,
                'industry' => $industryName,
                'industryToInvest' => $industryToInvestNames,
                'selectedLevels' => $request->level,
                'amount' => $request->amount,
                'age' => (string) $request->age,
                'field' => $fieldName,
                'work' => $request->workTitle,
                'referral' => $request->referral,
                'details' => $request->details,
                'sourceOfFundsUrl' => $sourceOfFundsUrl,
                'proofOfResidenceUrl' => $proofOfResidenceUrl,
                'timestamp' => now()->toDateTimeString(),
            ];

            // Save to Firestore
            $firestore->database()->collection('InvestorApplication')->document($userId)->set($data);
            Log::info('Data stored in Firestore: ' . json_encode($data));

            return redirect()->route('investor-application')->with('success', 'Application submitted successfully.');
        } catch (\Exception $e) {
            Log::error('Error in investor application store: ' . $e->getMessage() . "\nStack: " . $e->getTraceAsString());
            return redirect()->back()->with('error', 'Failed to submit application: ' . $e->getMessage())->withInput();
        }
    }
}