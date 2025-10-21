<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Kreait\Firebase\Factory;
use Illuminate\Support\Facades\Log;
use App\Mail\ServiceBookingStatusNotification;
use Illuminate\Support\Facades\Mail;

class ServiceProviderApprovalController extends Controller
{
    public function showApprovalForm($id)
    {
        return view('approve-service-booking', ['id' => $id]);
    }

    public function approveBooking(Request $request, $id)
    {
        try {
            $factory = (new Factory)->withServiceAccount(base_path(env('FIREBASE_CREDENTIALS')));
            $firestore = $factory->createFirestore();
            $auth = $factory->createAuth();
            
            $bookingDocRef = $firestore->database()->collection('ServicePayments')->document($id);
            $bookingSnapshot = $bookingDocRef->snapshot();
            $bookingData = $bookingSnapshot->data();
            
            // Fetch user email from Firebase Authentication
            $user = $auth->getUser($bookingData['userId']);
            $userEmail = $user->email;
            
            // Update approval status
            $status = $request->input('status') === 'approve';
            $bookingDocRef->update([
                ['path' => 'serviceProviderApproval', 'value' => $status]
            ]);

            // Prepare booking details for email
            $bookingDetails = [
                'userName' => $bookingData['userName'] ?? 'User',
                'serviceProviderName' => $bookingData['serviceProviderName'] ?? 'Provider',
                'serviceDate' => $bookingData['serviceDate'] ? \Carbon\Carbon::parse($bookingData['serviceDate'])->format('M d, Y') : 'N/A',
                'serviceTime' => $bookingData['serviceTime'] ?? 'N/A',
                'additionalInfo' => $bookingData['additionalInfo'] ?? 'None',
                'email' => $userEmail,
            ];

            // Log email sending attempt
            Log::info('Attempting to send service booking status email', [
                'booking_id' => $id,
                'user_id' => $bookingData['userId'],
                'email' => $userEmail,
                'status' => $status ? 'approved' : 'rejected'
            ]);

            // Send email notification
            try {
                Mail::to($bookingDetails['email'])
                ->bcc(config('mail.admin.to'))
                ->send(new ServiceBookingStatusNotification($bookingDetails, $status));
                Log::info('Service booking status email sent successfully', [
                    'booking_id' => $id,
                    'user_id' => $bookingData['userId'],
                    'email' => $userEmail
                ]);
            } catch (\Exception $mailException) {
                Log::error('Failed to send service booking status email', [
                    'booking_id' => $id,
                    'user_id' => $bookingData['userId'],
                    'email' => $userEmail,
                    'error' => $mailException->getMessage()
                ]);
            }

            $message = $status ? 'Approval has been successful.' : 'Booking has been rejected.';
            return redirect()->route('approve-service-booking', ['id' => $id])->with('success', $message);
        } catch (\Kreait\Firebase\Exception\Auth\UserNotFound $e) {
            Log::error('User not found in Firebase Auth', [
                'booking_id' => $id,
                'user_id' => $bookingData['userId'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            return redirect()->route('approve-service-booking', ['id' => $id])->with('error', 'User not found. Approval processed but email not sent.');
        } catch (\Exception $e) {
            Log::error('Error processing service booking', [
                'booking_id' => $id,
                'error' => $e->getMessage()
            ]);
            return redirect()->route('approve-service-booking', ['id' => $id])->with('error', 'Action failed. Please try again.');
        }
    }
}