<?php

namespace App\Livewire\More;

use Livewire\Component;
use Kreait\Firebase\Factory;
use Google\Cloud\Firestore\FieldValue;
use Illuminate\Support\Facades\Log;

class WishList extends Component
{
    public $marketplaceData = [];
    public $modalData = null;
    public bool $marketplaceModal = false;
    public $companyValuation = 0;
    public $equityAmount = 0;

    public function mount()
    {
        $this->fetchMarketplaceData();
    }

    public function fetchMarketplaceData()
    {
        $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
        $firestore = $factory->createFirestore();
        $collection = $firestore->database()->collection('Marketplace');
        $documents = $collection->documents();
    
        $userId = session('firebase_user');
        $this->marketplaceData = [];
        foreach ($documents as $document) {
            $data = $document->data();
            $data['id'] = $document->id();
            if (isset($data['investors'])) {
                foreach ($data['investors'] as $investor) {
                    if ($investor['investorId'] === $userId && !$investor['investmentComplete']) {
                        // Convert timestamp to string if necessary
                        if (isset($data['createdAt']) && $data['createdAt'] instanceof \Google\Cloud\Core\Timestamp) {
                            $data['createdAt'] = $data['createdAt']->get()->format('Y-m-d H:i:s');
                        }
                        if (isset($data['subscriptionExpiry']) && $data['subscriptionExpiry'] instanceof \Google\Cloud\Core\Timestamp) {
                            $data['subscriptionExpiry'] = $data['subscriptionExpiry']->get()->format('Y-m-d H:i:s');
                        }
                        $this->marketplaceData[] = $data;
                        break;
                    }
                }
            }
        }
    }

    public function openMarketplaceModal($index)
    {
        $this->modalData = $this->marketplaceData[$index];
        $this->calculateCompanyValuationAndEquity();
        $this->marketplaceModal = true;
    }
    
    public function calculateCompanyValuationAndEquity()
    {
        if ($this->modalData['earnings'] != null && $this->modalData['earnings'] != 0) {
            $this->companyValuation = $this->modalData['earnings'] * 8;
        } else {
            $this->companyValuation = 0;
        }
    
        if ($this->modalData['earnings'] != null && $this->modalData['companyAsk'] != 0) {
            $this->equityAmount = $this->modalData['companyAsk'] / ($this->modalData['earnings'] * 8);
        } else {
            $this->equityAmount = 0;
        }
    }

    public function toggleInterest($index, $type)
    {
        $userId = session('firebase_user');
        $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
        $firestore = $factory->createFirestore();
        $docRef = $firestore->database()
            ->collection('Marketplace')
            ->document($this->marketplaceData[$index]['id']);

        // Remove from opposite array if exists
        $oppositeType = $type === 'interest' ? 'disinterest' : 'interest';
        $oppositeArray = 'showed' . ucfirst($oppositeType) . 'Users';
        if (isset($this->marketplaceData[$index][$oppositeArray]) && 
            in_array($userId, $this->marketplaceData[$index][$oppositeArray])) {
            $docRef->update([
                ['path' => $oppositeArray, 'value' => FieldValue::arrayRemove([$userId])]
            ]);
            // Update local data
            $this->marketplaceData[$index][$oppositeArray] = array_diff($this->marketplaceData[$index][$oppositeArray], [$userId]);
        }

        // Toggle in current array
        $currentArray = 'showed' . ucfirst($type) . 'Users';
        if (!isset($this->marketplaceData[$index][$currentArray]) || 
            !in_array($userId, $this->marketplaceData[$index][$currentArray])) {
            $docRef->update([
                ['path' => $currentArray, 'value' => FieldValue::arrayUnion([$userId])]
            ]);
            // Update local data
            $this->marketplaceData[$index][$currentArray][] = $userId;
        } else {
            $docRef->update([
                ['path' => $currentArray, 'value' => FieldValue::arrayRemove([$userId])]
            ]);
            // Update local data
            $this->marketplaceData[$index][$currentArray] = array_diff($this->marketplaceData[$index][$currentArray], [$userId]);
        }
    }

    public function openComments($index)
    {
        // Placeholder for comments functionality
        $this->redirect(route('marketplace.comments', ['index' => $index]));
    }

    public function render()
    {
        return view('livewire.more.wish-list', [
            'marketplaceData' => $this->marketplaceData,
            'companyValuation' => $this->companyValuation,
            'equityAmount' => $this->equityAmount,
        ]);
    }
}