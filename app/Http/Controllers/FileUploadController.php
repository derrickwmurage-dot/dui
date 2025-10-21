<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Kreait\Firebase\Factory;
use Illuminate\Support\Facades\Log;

class FileUploadController extends Controller
{
    public function uploadProofOfResidence(Request $request)
    {
        return $this->handleFileUpload($request, 'proof_of_residence', 'investor_applications');
    }

    public function uploadSourceOfFunds(Request $request)
    {
        return $this->handleFileUpload($request, 'source_of_funds', 'investor_applications');
    }

    public function uploadPitchdeck(Request $request)
    {
        return $this->handleFileUpload($request, 'pitchdeck', 'documents');
    }

    private function handleFileUpload(Request $request, $filePrefix, $folder)
    {
        try {
            Log::info("File upload called for: {$filePrefix} in folder: {$folder}");
    
            // Validate file presence
            if (!$request->hasFile('file')) {
                throw new \Exception('No file uploaded');
            }

            $file = $request->file('file');
            Log::info('File details:', [
                'originalName' => $file->getClientOriginalName(),
                'mimeType' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'isValid' => $file->isValid(),
            ]);
    
            // Check if the file is valid
            if (!$file->isValid()) {
                throw new \Exception('The file is not valid.');
            }
    
            // Check file existence and readability
            if (!file_exists($file->getPathname())) {
                throw new \Exception('The file does not exist at the specified path.');
            }
            if (!is_readable($file->getPathname())) {
                throw new \Exception('The file is not readable.');
            }
            Log::info('File exists and is readable.');
    
            // Firebase setup
            $credentialsPath = storage_path('firebase/firebase-credentials.json');
            if (!file_exists($credentialsPath)) {
                throw new \Exception('Firebase credentials not found at: ' . $credentialsPath);
            }
    
            $factory = (new Factory)->withServiceAccount($credentialsPath);
            $storage = $factory->createStorage();
            $bucket = $storage->getBucket();
            Log::info('Firebase initialized with bucket: ' . $bucket->name());
    
            $userId = session('firebase_user');
            if (!$userId) {
                throw new \Exception('User not authenticated');
            }
            Log::info('User ID: ' . $userId);
    
            // Upload the file to Firebase Storage
            $filePath = "{$folder}/{$userId}_{$filePrefix}_" . time() . '.' . $file->getClientOriginalExtension();
            $fileStream = fopen($file->getPathname(), 'r');
            if (!$fileStream) {
                throw new \Exception('Failed to open file stream.');
            }
            Log::info('File stream opened successfully.');
    
            $uploadedFile = $bucket->upload($fileStream, [
                'name' => $filePath,
                'predefinedAcl' => 'publicRead', // Make the file publicly accessible
            ]);
            $fileUrl = "https://storage.googleapis.com/{$bucket->name()}/{$filePath}";
            Log::info("File uploaded successfully: {$fileUrl}");
    
            return response()->json([
                'success' => true,
                'url' => $fileUrl,
            ]);
        } catch (\Exception $e) {
            Log::error("Error uploading file for {$filePrefix} in {$folder}: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        } finally {
            // Clean up file stream if it was opened
            if (isset($fileStream) && is_resource($fileStream)) {
                fclose($fileStream);
            }
        }
    }

    public function uploadMarketplaceFile(Request $request)
    {
        try {
            Log::info("File upload called for marketplace");

            if (!$request->hasFile('file')) {
                throw new \Exception('No file uploaded');
            }

            $file = $request->file('file');
            if (!$file->isValid()) {
                throw new \Exception('The file is not valid.');
            }
            if ($file->getClientMimeType() !== 'application/pdf') {
                throw new \Exception('Only PDF files are allowed.');
            }
            if ($file->getSize() > 2 * 1024 * 1024) { // 2MB limit
                throw new \Exception('File exceeds 2MB limit.');
            }

            $credentialsPath = storage_path('firebase/firebase-credentials.json');
            if (!file_exists($credentialsPath)) {
                throw new \Exception('Firebase credentials not found at: ' . $credentialsPath);
            }

            $factory = (new Factory)->withServiceAccount($credentialsPath);
            $storage = $factory->createStorage();
            $bucket = $storage->getBucket();

            $userId = session('firebase_user');
            if (!$userId) {
                throw new \Exception('User not authenticated');
            }

            $filePath = "documents/{$userId}_marketplace_" . time() . '.pdf';
            $fileStream = fopen($file->getPathname(), 'r');
            if (!$fileStream) {
                throw new \Exception('Failed to open file stream.');
            }

            $uploadedFile = $bucket->upload($fileStream, [
                'name' => $filePath,
                'predefinedAcl' => 'publicRead',
            ]);
            $fileUrl = "https://storage.googleapis.com/{$bucket->name()}/{$filePath}";

            return response()->json([
                'success' => true,
                'url' => $fileUrl,
            ]);
        } catch (\Exception $e) {
            Log::error("Error uploading marketplace file: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        } finally {
            if (isset($fileStream) && is_resource($fileStream)) {
                fclose($fileStream);
            }
        }
    }
}