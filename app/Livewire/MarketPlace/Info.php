<?php

namespace App\Livewire\Marketplace;

use Livewire\Component;
use Kreait\Firebase\Factory;

class Info extends Component
{
    public $id;
    public $moreInfo;

    public function mount($id)
    {
        $this->id = $id;
        $this->fetchMarketplaceData();
    }

    public function fetchMarketplaceData()
    {
        $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
        $firestore = $factory->createFirestore();
        $docRef = $firestore->database()->collection('Marketplace')->document($this->id);
        $snapshot = $docRef->snapshot();
    
        if ($snapshot->exists()) {
            $data = $snapshot->data();
    
            // Convert any Timestamp objects to strings
            array_walk_recursive($data, function(&$value) {
                if ($value instanceof \Google\Cloud\Core\Timestamp) {
                    $value = $value->get()->format('Y-m-d H:i:s');
                }
            });
    
            $this->moreInfo = $data['moreInfo'] ?? null;
        } else {
            $this->moreInfo = null;
        }
    }

    public function render()
    {
        return view('livewire.marketplace.info', [
            'moreInfo' => $this->moreInfo,
        ]);
    }
    
}