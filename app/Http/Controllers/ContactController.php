<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use App\Mail\ContactFormMail;
use Illuminate\Support\Facades\Log;

class ContactController extends Controller
{
    public function send(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required',
            'last_name' => 'required',
            'email' => 'required|email',
            'company_name' => 'required',
            'message' => 'required',
        ]);

        try {
            // Send email
            Mail::to('munyaolance1@gmail.com')
            ->bcc(config('mail.admin.to'))
            ->send(new ContactFormMail(
                $validated['first_name'],
                $validated['last_name'],
                $validated['email'],
                $validated['company_name'],
                $validated['message']
            ));

            // Log success
            Log::info('Contact form email sent successfully.', $validated);

            return response()->json(['success' => "Thank you for reaching out. We'll get back to you shortly."], 200);
        } catch (\Exception $e) {
            // Log error
            Log::error('Failed to send contact form email.', ['error' => $e->getMessage()] + $validated);

            return response()->json(['error' => "Failed to send your message. Please try again later."], 500);
        }
    }
}
