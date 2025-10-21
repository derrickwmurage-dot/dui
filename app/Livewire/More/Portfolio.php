<?php

namespace App\Livewire\More;

use Livewire\Component;
use Kreait\Firebase\Factory;
use Google\Cloud\Firestore\FieldValue;
use Illuminate\Support\Facades\Log;
use Mary\Traits\Toast;
use Livewire\WithFileUploads;

class Portfolio extends Component
{
    use Toast, WithFileUploads;
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
        try {
            $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            $firestore = $factory->createFirestore();
            $collection = $firestore->database()->collection('Marketplace');
            $documents = $collection->where('verified', '=', true)->documents();
    
            $userId = session('firebase_user');
            $marketplaceData = [];
            foreach ($documents as $document) {
                $data = $document->data();
                $data['id'] = $document->id();
                if (isset($data['investors'])) {
                    foreach ($data['investors'] as $investor) {
                        if ($investor['investorId'] === $userId && $investor['investmentComplete'] === true) {
                            // Convert Firestore timestamps to strings
                            if (isset($data['createdAt']) && $data['createdAt'] instanceof \Google\Cloud\Core\Timestamp) {
                                $data['createdAt'] = $data['createdAt']->get()->format('Y-m-d H:i:s');
                            }
                            if (isset($data['subscriptionExpiry']) && $data['subscriptionExpiry'] instanceof \Google\Cloud\Core\Timestamp) {
                                $data['subscriptionExpiry'] = $data['subscriptionExpiry']->get()->format('Y-m-d H:i:s');
                            }
                            $marketplaceData[] = $data;
                            break;
                        }
                    }
                }
            }
            $this->marketplaceData = $marketplaceData;
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            $this->error('An error occurred. Please try again.');
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

        // Check if the user has filled the investor application form
        $investorDoc = $firestore->database()->collection('InvestorApplication')->document($userId)->snapshot();
        if (!$investorDoc->exists()) {
            $this->error('Please complete the investor application form first');
            return;
        }

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
        $documentId = $this->marketplaceData[$index]['id'];
        $this->redirect(route('marketplace.comments', ['id' => $documentId]));
    }

    public function openMoreInfo($index)
    {
        $documentId = $this->marketplaceData[$index]['id'];
        $this->redirect(route('marketplace.info', ['id' => $documentId]));
    }

    public function render()
    {
        return view('livewire.more.portfolio', [
            'marketplaceData' => $this->marketplaceData,
            'companyValuation' => $this->companyValuation,
            'equityAmount' => $this->equityAmount,
        ]);
    }
}