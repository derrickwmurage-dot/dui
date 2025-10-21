<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Kreait\Firebase\Factory;
use Illuminate\Support\Facades\Log;
use App\Mail\BookingStatusNotification;
use Illuminate\Support\Facades\Mail;

class BookingApprovalController extends Controller
{
    public function showApprovalForm($id)
    {
        return view('approve-booking', ['id' => $id]);
    }

    public function approveBooking(Request $request, $id)
    {
        try {
            $factory = (new Factory)->withServiceAccount(base_path(env('FIREBASE_CREDENTIALS')));
            $firestore = $factory->createFirestore();
            $auth = $factory->createAuth();
            
            $bookingDocRef = $firestore->database()->collection('CollaborativeSpacePayments')->document($id);
            $bookingSnapshot = $bookingDocRef->snapshot();
            $bookingData = $bookingSnapshot->data();
            
            // Fetch user email from Firebase Authentication
            $user = $auth->getUser($bookingData['userId']);
            $userEmail = $user->email;
            
            // Update approval status
            $status = $request->input('status') === 'approve';
            $bookingDocRef->update([
                ['path' => 'managerApproval', 'value' => $status]
            ]);

            // Prepare booking details for email
            $bookingDetails = [
                'userName' => $bookingData['userName'] ?? 'User',
                'studioName' => $bookingData['studioName'] ?? 'Studio',
                'startDate' => $bookingData['startDate'] ? \Carbon\Carbon::parse($bookingData['startDate'])->format('M d, Y') : 'N/A',
                'days' => $bookingData['days'] ?? 1,
                'amount' => $bookingData['amount'] ?? 0,
                'email' => $userEmail,
            ];

            // Log email sending attempt
            Log::info('Attempting to send booking status email', [
                'booking_id' => $id,
                'user_id' => $bookingData['userId'],
                'email' => $userEmail,
                'status' => $status ? 'approved' : 'rejected'
            ]);

            // Send email notification
            try {
                Mail::to($bookingDetails['email'])
                ->bcc(config('mail.admin.to'))
                ->send(new BookingStatusNotification($bookingDetails, $status));
                Log::info('Booking status email sent successfully', [
                    'booking_id' => $id,
                    'user_id' => $bookingData['userId'],
                    'email' => $userEmail
                ]);
            } catch (\Exception $mailException) {
                Log::error('Failed to send booking status email', [
                    'booking_id' => $id,
                    'user_id' => $bookingData['userId'],
                    'email' => $userEmail,
                    'error' => $mailException->getMessage()
                ]);
                // Optionally, you could redirect with a specific message here
            }

            $message = $status ? 'Approval has been successful.' : 'Booking has been rejected.';
            return redirect()->route('approve-booking', ['id' => $id])->with('success', $message);
        } catch (\Kreait\Firebase\Exception\Auth\UserNotFound $e) {
            Log::error('User not found in Firebase Auth', [
                'booking_id' => $id,
                'user_id' => $bookingData['userId'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            return redirect()->route('approve-booking', ['id' => $id])->with('error', 'User not found. Approval processed but email not sent.');
        } catch (\Exception $e) {
            Log::error('Error processing booking', [
                'booking_id' => $id,
                'error' => $e->getMessage()
            ]);
            return redirect()->route('approve-booking', ['id' => $id])->with('error', 'Action failed. Please try again.');
        }
    }
}