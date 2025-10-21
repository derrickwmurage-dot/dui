<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Kreait\Firebase\Factory;
use Illuminate\Support\Facades\Log;

class KYCController extends Controller
{
    public function store(Request $request)
    {
        Log::info('KYC store called with data: ' . json_encode($request->all()));
        Log::info('Request files: ' . json_encode($request->allFiles()));

        try {
            $validated = $request->validate([
                'idPhoto' => 'required|image|max:1024', // 1MB
                'profilePhoto' => 'required|image|max:1024', // 1MB
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

            // Upload ID Photo
            if ($request->hasFile('idPhoto')) {
                $idPhoto = $request->file('idPhoto');
                if (!$idPhoto->isValid()) {
                    throw new \Exception('Invalid ID photo: ' . $idPhoto->getErrorMessage());
                }
                $idPhotoPath = 'kyc/' . $userId . '_id.' . $idPhoto->getClientOriginalExtension();
                $idPhotoFile = fopen($idPhoto->getPathname(), 'r');
                $idPhotoObject = $bucket->upload($idPhotoFile, [
                    'name' => $idPhotoPath,
                    'predefinedAcl' => 'publicRead',
                ]);
                $idPhotoUrl = $idPhotoObject->signedUrl(new \DateTime('+10 years'));
                Log::info('ID photo uploaded: ' . $idPhotoUrl);
            } else {
                throw new \Exception('ID photo is required');
            }

            // Upload Profile Photo
            if ($request->hasFile('profilePhoto')) {
                $profilePhoto = $request->file('profilePhoto');
                if (!$profilePhoto->isValid()) {
                    throw new \Exception('Invalid profile photo: ' . $profilePhoto->getErrorMessage());
                }
                $profilePhotoPath = 'kyc/' . $userId . '_profile.' . $profilePhoto->getClientOriginalExtension();
                $profilePhotoFile = fopen($profilePhoto->getPathname(), 'r');
                $profilePhotoObject = $bucket->upload($profilePhotoFile, [
                    'name' => $profilePhotoPath,
                    'predefinedAcl' => 'publicRead',
                ]);
                $profilePhotoUrl = $profilePhotoObject->signedUrl(new \DateTime('+10 years'));
                Log::info('Profile photo uploaded: ' . $profilePhotoUrl);
            } else {
                throw new \Exception('Profile photo is required');
            }

            // Save to Firestore
            $kycData = [
                'userId' => $userId,
                'idImageUrl' => $idPhotoUrl,
                'profileImageUrl' => $profilePhotoUrl,
                'approved' => false,
            ];
            $firestore->database()->collection('KYC')->document($userId)->set($kycData);
            Log::info('KYC data stored in Firestore: ' . json_encode($kycData));

            return redirect()->route('profile')->with('success', 'KYC documents submitted successfully.');
        } catch (\Exception $e) {
            Log::error('Error in KYC store: ' . $e->getMessage() . "\nStack: " . $e->getTraceAsString());
            return redirect()->back()->with('error', 'Failed to submit KYC documents: ' . $e->getMessage())->withInput();
        }
    }
}