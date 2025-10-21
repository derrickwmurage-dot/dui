<?php

namespace App\Livewire\Marketplace;

use Livewire\Component;
use Kreait\Firebase\Factory;
use Google\Cloud\Firestore\FieldValue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mary\Traits\Toast;
use App\Mail\InvestmentApproved;
use App\Mail\InvestmentRejected;
use App\Mail\InvestmentApprovedAdminNotification;

class Investors extends Component
{
    use Toast;

    public $marketplaceData;
    public $marketplaceId;

    public function mount($id)
    {
        $this->marketplaceId = $id;
        $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
        $firestore = $factory->createFirestore();
        $marketplaceDoc = $firestore->database()->collection('Marketplace')->document($id)->snapshot();

        if (!$marketplaceDoc->exists()) {
            abort(404, 'Marketplace item not found');
        }

        $this->marketplaceData = $marketplaceDoc->data();
        $this->marketplaceData['investors'] = $this->marketplaceData['investors'] ?? [];

        // Convert Firestore timestamps to strings for display
        foreach ($this->marketplaceData['investors'] as &$investor) {
            if (isset($investor['date']) && $investor['date'] instanceof \Google\Cloud\Core\Timestamp) {
                $investor['date'] = $investor['date']->get()->format('Y-m-d H:i:s');
            }
        }
        foreach ($this->marketplaceData as $key => $value) {
            if ($value instanceof \Google\Cloud\Core\Timestamp) {
                $this->marketplaceData[$key] = $value->get()->format('Y-m-d H:i:s');
            }
        }
    }

    public function approveInvestment($investorId)
    {
        try {
            $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            $firestore = $factory->createFirestore();
            $auth = $factory->createAuth();
            $marketplaceDocRef = $firestore->database()->collection('Marketplace')->document($this->marketplaceId);
    
            $marketplaceDoc = $marketplaceDocRef->snapshot();
            if (!$marketplaceDoc->exists()) {
                throw new \Exception('Marketplace item not found');
            }
    
            $investors = $marketplaceDoc->data()['investors'] ?? [];
            $investorToApprove = null;
            foreach ($investors as &$investor) {
                if ($investor['investorId'] === $investorId) {
                    $investor['approved'] = true;
                    $investorToApprove = $investor;
                    break;
                }
            }
    
            if (!$investorToApprove) {
                throw new \Exception('Investor not found');
            }
    
            $marketplaceDocRef->update([
                ['path' => 'investors', 'value' => $investors]
            ]);
            Log::info("Investor $investorId approved for marketplace {$this->marketplaceId}");
    
            // Fetch investor email from Firebase Auth
            $investorUser = $auth->getUser($investorId);
            $investorEmail = $investorUser->email;
            $investorName = $investorToApprove['investorName'] ?? 'Investor';
            Log::info("Fetched investor email: $investorEmail for investor ID: $investorId");
    
            // Fetch marketplace owner email from document or Firebase Auth
            $creatorId = $marketplaceDoc->data()['creator'] ?? null;
            $ownerEmail = $marketplaceDoc->data()['creatorEmail'] ?? null;
            
            if (!$creatorId) {
                throw new \Exception('Marketplace creator ID not found');
            }
            
            // If creatorEmail isn't set, fetch it from Firebase Auth
            if (!$ownerEmail) {
                $ownerUser = $auth->getUser($creatorId);
                $ownerEmail = $ownerUser->email;
                Log::info("Fetched owner email from Firebase Auth: $ownerEmail for creator ID: $creatorId");
            } else {
                Log::info("Using owner email from document: $ownerEmail for creator ID: $creatorId");
            }
    
            // Send approval email to investor
            Log::info("Attempting to send approval email to investor: $investorEmail");
            Mail::to($investorEmail)
                ->bcc(config('mail.admin.to'))
                ->send(new InvestmentApproved(
                    $investorName,
                    $this->marketplaceData['title'],
                    $investorToApprove['investmentAmount'],
                    $investorToApprove['equity']
                ));
            Log::info("Approval email queued successfully for investor: $investorEmail");
    
            // Send notification to admin with both emails
            $adminEmail = config('mail.admin.to');
            Log::info("Attempting to send admin notification to: $adminEmail with investor email: $investorEmail and owner email: $ownerEmail");
            Mail::to($adminEmail)
                ->send(new InvestmentApprovedAdminNotification(
                    $investorEmail,
                    $ownerEmail,
                    $this->marketplaceData['title'],
                    $investorName,
                    $investorToApprove['investmentAmount'],
                    $investorToApprove['equity']
                ));
            Log::info("Admin notification email queued successfully to: $adminEmail");
    
            $this->success('Investment approved successfully');
            $this->marketplaceData['investors'] = $investors;
        } catch (\Exception $e) {
            Log::error("Error approving investment for $investorId: " . $e->getMessage() . "\nStack: " . $e->getTraceAsString());
            $this->error('Failed to approve investment: ' . $e->getMessage());
        }
    }

    public function rejectInvestment($investorId)
    {
        try {
            $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            $firestore = $factory->createFirestore();
            $auth = $factory->createAuth();
            $marketplaceDocRef = $firestore->database()->collection('Marketplace')->document($this->marketplaceId);

            $marketplaceDoc = $marketplaceDocRef->snapshot();
            if (!$marketplaceDoc->exists()) {
                throw new \Exception('Marketplace item not found');
            }

            $investors = $marketplaceDoc->data()['investors'] ?? [];
            $investorToReject = null;
            $updatedInvestors = [];
            foreach ($investors as $investor) {
                if ($investor['investorId'] === $investorId) {
                    $investorToReject = $investor;
                } else {
                    $updatedInvestors[] = $investor;
                }
            }

            if (!$investorToReject) {
                throw new \Exception('Investor not found');
            }

            $marketplaceDocRef->update([
                ['path' => 'investors', 'value' => $updatedInvestors]
            ]);
            Log::info("Investor $investorId rejected and removed from marketplace {$this->marketplaceId}");

            // Fetch investor email from Firebase Auth
            $user = $auth->getUser($investorId);
            $email = $user->email;
            $investorName = $investorToReject['investorName'] ?? 'Investor';

            // Send rejection email
            Log::info("Attempting to send rejection email to investor: $email");
            Mail::to($email)
                ->bcc(config('mail.admin.to'))
                ->send(new InvestmentRejected(
                    $investorName,
                    $this->marketplaceData['title'],
                    'Your investment did not meet our criteria at this time.'
                ));
            Log::info("Rejection email queued successfully for investor: $email");

            $this->success('Investment rejected successfully');
            $this->marketplaceData['investors'] = $updatedInvestors;
        } catch (\Exception $e) {
            Log::error("Error rejecting investment for $investorId: " . $e->getMessage() . "\nStack: " . $e->getTraceAsString());
            $this->error('Failed to reject investment: ' . $e->getMessage());
        }
    }

    public function backToMarketplace()
    {
        return redirect('/marketplace');
    }

    public function render()
    {
        return view('livewire.marketplace.investors', [
            'marketplaceData' => $this->marketplaceData,
        ]);
    }
}