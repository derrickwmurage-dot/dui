<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Kreait\Firebase\Factory;
use Illuminate\Support\Facades\Log;

class ImageUploadController extends Controller
{
    public function uploadIdPhoto(Request $request)
    {
        return $this->handleImageUpload($request, 'id_photo', 'kyc_images');
    }

    public function uploadProfilePhoto(Request $request)
    {
        return $this->handleImageUpload($request, 'profile_photo', 'kyc_images');
    }

    private function handleImageUpload(Request $request, $filePrefix, $folder)
    {
        try {
            Log::info("Image upload called for: {$filePrefix} in folder: {$folder}");

            if (!$request->hasFile('file')) {
                throw new \Exception('No file uploaded');
            }

            $file = $request->file('file');
            if (!$file->isValid()) {
                throw new \Exception('The file is not valid.');
            }
            if (!in_array($file->getClientMimeType(), ['image/jpeg', 'image/png', 'image/gif'])) {
                throw new \Exception('Only JPEG, PNG, and GIF images are allowed.');
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

            $filePath = "{$folder}/{$userId}_{$filePrefix}_" . time() . '.' . $file->getClientOriginalExtension();
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
            Log::error("Error uploading image for {$filePrefix} in {$folder}: " . $e->getMessage());
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

    public function uploadMarketplaceImage(Request $request)
    {
        try {
            Log::info("Image upload called for: marketplace_image in folder: images");

            if (!$request->hasFile('images')) {
                throw new \Exception('No images uploaded');
            }

            $files = $request->file('images');
            if (!is_array($files)) {
                $files = [$files];
            }

            $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
            $urls = [];

            $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            $storage = $factory->createStorage();
            $bucket = $storage->getBucket();

            $userId = session('firebase_user');
            if (!$userId) {
                throw new \Exception('User not authenticated');
            }

            foreach ($files as $index => $file) {
                if (!$file->isValid()) {
                    throw new \Exception("Image {$index} is not valid.");
                }
                if (!in_array($file->getClientMimeType(), $allowedMimes)) {
                    throw new \Exception("Image {$index}: Only JPEG, PNG, and GIF images are allowed.");
                }

                $filePath = "images/{$userId}_marketplace_image_" . time() . "_{$index}." . $file->getClientOriginalExtension();
                $fileStream = fopen($file->getPathname(), 'r');
                if ($fileStream === false) {
                    throw new \Exception("Failed to open file stream for image {$index}.");
                }

                try {
                    $bucket->upload($fileStream, [
                        'name' => $filePath,
                        'predefinedAcl' => 'publicRead',
                    ]);
                    $urls[] = "https://storage.googleapis.com/{$bucket->name()}/{$filePath}";
                } finally {
                    if (is_resource($fileStream)) {
                        fclose($fileStream);
                    }
                }
            }

            return response()->json([
                'success' => true,
                'urls' => $urls,
            ]);
        } catch (\Exception $e) {
            Log::error("Error uploading images for marketplace_image in images: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}