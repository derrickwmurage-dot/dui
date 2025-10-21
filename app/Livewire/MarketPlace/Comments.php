<?php

namespace App\Livewire\Marketplace;

use Livewire\Component;
use Kreait\Firebase\Factory;
use Google\Cloud\Firestore\FieldValue;
use Mary\Traits\Toast;

class Comments extends Component
{
    use Toast;
    public $index;
    public $marketplaceData;
    public $averageRating;
    public bool $reviewModal = false;
    public $reviewerName, $reviewText, $reviewRating;
    public $userHasReviewed = false;
    public $isUserInvestor = false;
    public $userId;

    public function mount($id)
    {
        $this->userId = session('firebase_user');
        $this->fetchMarketplaceData($id);
        $this->isUserInvestor = $this->checkIfUserIsInvestor();
        $this->calculateAverageRating();
        $this->checkIfUserHasReviewed();
    }
    
    public function fetchMarketplaceData($id)
    {
        $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
        $firestore = $factory->createFirestore();
        $document = $firestore->database()->collection('Marketplace')->document($id)->snapshot();
    
        if ($document->exists()) {
            $data = $document->data();
            $data['id'] = $document->id();
            array_walk_recursive($data, function(&$value) {
                if ($value instanceof \Google\Cloud\Core\Timestamp) {
                    $value = $value->get()->format('Y-m-d H:i:s');
                }
            });
    
            $this->marketplaceData = $data;
        } else {
            $this->marketplaceData = null;
        }
    }

    public function checkIfUserIsInvestor()
    {
        $userId = session('firebase_user');
        if (isset($this->marketplaceData['investors'])) {
            foreach ($this->marketplaceData['investors'] as $investor) {
                if (isset($investor['investorId']) && $investor['investorId'] === $userId && $investor['investmentComplete'] === true) {
                    return true;
                }
            }
        }
        return false;
    }

    public function toggleInterest($type)
    {
        $userId = session('firebase_user');
        if (!$userId) {
            $this->error(
                title: 'User not authenticated',
                position: 'toast-top toast-center',
                css: 'max-w-[90vw] w-auto',
                timeout: 4000
            );
            return;
        }

        $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
        $firestore = $factory->createFirestore();
        $docRef = $firestore->database()->collection('Marketplace')->document($this->marketplaceData['id']);
        
        $snapshot = $docRef->snapshot();
        $currentInterestUsers = $snapshot->data()['showedInterestUsers'] ?? [];
        $currentDisinterestUsers = $snapshot->data()['showedDisinterestUsers'] ?? [];

        if ($type === 'interest') {
            if (in_array($userId, $currentInterestUsers)) {
                $docRef->update([
                    ['path' => 'showedInterestUsers', 'value' => FieldValue::arrayRemove([$userId])]
                ]);
            } else {
                $docRef->update([
                    ['path' => 'showedInterestUsers', 'value' => FieldValue::arrayUnion([$userId])]
                ]);
                if (in_array($userId, $currentDisinterestUsers)) {
                    $docRef->update([
                        ['path' => 'showedDisinterestUsers', 'value' => FieldValue::arrayRemove([$userId])]
                    ]);
                }
            }
        } elseif ($type === 'disinterest') {
            if (in_array($userId, $currentDisinterestUsers)) {
                $docRef->update([
                    ['path' => 'showedDisinterestUsers', 'value' => FieldValue::arrayRemove([$userId])]
                ]);
            } else {
                $docRef->update([
                    ['path' => 'showedDisinterestUsers', 'value' => FieldValue::arrayUnion([$userId])]
                ]);
                if (in_array($userId, $currentInterestUsers)) {
                    $docRef->update([
                        ['path' => 'showedInterestUsers', 'value' => FieldValue::arrayRemove([$userId])]
                    ]);
                }
            }
        }

        $this->fetchMarketplaceData($this->marketplaceData['id']);
    }

    public function openReviewModal()
    {
        if ($this->userHasReviewed) {
            $this->error(
                title: 'You have already submitted a review for this item.',
                position: 'toast-top toast-center',
                css: 'max-w-[90vw] w-auto',
                timeout: 4000
            );
            return;
        }
        $this->reviewModal = true;
    }

    public function addReview()
    {
        $userId = session('firebase_user');
        if (!$userId) {
            $this->error(
                title: 'User not authenticated',
                position: 'toast-top toast-center',
                css: 'max-w-[90vw] w-auto',
                timeout: 4000
            );
            return;
        }
    
        $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
        $firestore = $factory->createFirestore();
        $docRef = $firestore->database()
            ->collection('Marketplace')
            ->document($this->marketplaceData['id']);
        
        $snapshot = $docRef->snapshot();
        $reviews = $snapshot->data()['reviews'] ?? [];
    
        $hasReviewed = collect($reviews)->contains('reviewerId', $userId);
        if ($hasReviewed) {
            $this->error(
                title: 'You have already submitted a review for this item.',
                position: 'toast-top toast-center',
                css: 'max-w-[90vw] w-auto',
                timeout: 4000
            );
            return;
        }
    
        $reviewData = [
            'date' => (new \DateTime())->format('d-m-Y'),
            'rating' => (int) $this->reviewRating,
            'reviewText' => $this->reviewText,
            'reviewer' => $this->reviewerName,
            'reviewerId' => $userId,
        ];
        
        $docRef->update([
            ['path' => 'reviews', 'value' => FieldValue::arrayUnion([$reviewData])]
        ]);
        
        $this->reviewModal = false;
        $this->reviewerName = '';
        $this->reviewText = '';
        $this->reviewRating = '';
        $this->fetchMarketplaceData($this->marketplaceData['id']);
        $this->calculateAverageRating();
        $this->checkIfUserHasReviewed();
        
        session(['user_has_reviewed_' . $this->marketplaceData['id'] => true]);
        $this->success(
            title: 'Review added successfully',
            position: 'toast-top toast-center',
            css: 'max-w-[90vw] w-auto',
            timeout: 4000
        );
    }

    public function calculateAverageRating()
    {
        if (isset($this->marketplaceData['reviews']) && count($this->marketplaceData['reviews']) > 0) {
            $totalRating = array_sum(array_column($this->marketplaceData['reviews'], 'rating'));
            $this->averageRating = $totalRating / count($this->marketplaceData['reviews']);
        } else {
            $this->averageRating = 0;
        }
    }

    public function checkIfUserHasReviewed()
    {
        $userId = session('firebase_user');
        $this->userHasReviewed = false;
        if (isset($this->marketplaceData['reviews'])) {
            foreach ($this->marketplaceData['reviews'] as $review) {
                if (isset($review['reviewerId']) && $review['reviewerId'] === $userId) {
                    $this->userHasReviewed = true;
                    session(['user_has_reviewed_' . $this->marketplaceData['id'] => true]);
                    break;
                }
            }
        }
    }

    public function render()
    {
        return view('livewire.marketplace.comments', [
            'marketplaceData' => $this->marketplaceData,
            'averageRating' => $this->averageRating,
            'userHasReviewed' => $this->userHasReviewed,
        ]);
    }
}