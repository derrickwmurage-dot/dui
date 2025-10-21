<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Exception\Auth\InvalidPassword;
use Kreait\Firebase\Exception\Auth\UserNotFound;
use Kreait\Firebase\Exception\AuthException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use App\Mail\WelcomeMail;
use Carbon\Carbon;

class AuthController extends Controller
{
    protected $auth;
    protected $firestore;

    public function __construct()
    {
        $firebaseCredentials = base_path('/storage/firebase/firebase-credentials.json');
        try {
            $factory = (new Factory)->withServiceAccount($firebaseCredentials);
            $this->auth = $factory->createAuth();
            $this->firestore = $factory->createFirestore();
            error_log('CHECKPOINT_FIREBASE_INIT: Firebase initialized successfully');
        } catch (\Exception $e) {
            error_log('CHECKPOINT_FIREBASE_INIT_FAILED: Firebase initialization failed: ' . $e->getMessage());
        }
    }

    // Show Login Form
    public function showLoginForm()
    {
        error_log('CHECKPOINT_LOGIN_FORM: Login form accessed, ip: ' . request()->ip());
        return view('login');
    }

    // Show Register Form
    public function showRegisterForm()
    {
        error_log('CHECKPOINT_REGISTER_FORM: Register form accessed, ip: ' . request()->ip());
        return view('register');
    }

    // Handle User Registration
    public function register(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|min:6',
        ]);

        try {
            error_log('CHECKPOINT_REGISTER_ATTEMPT: Registration attempt for email: ' . $request->email . ', ip: ' . $request->ip());
            $user = $this->auth->createUserWithEmailAndPassword($request->email, $request->password);
            $userId = $user->uid;
            error_log('CHECKPOINT_REGISTER_USER_CREATED: User created, user_id: ' . $userId . ', email: ' . $request->email);

            $userDocRef = $this->firestore->database()->collection('Users')->document($userId);
            $userDoc = $userDocRef->snapshot();

            if (!$userDoc->exists() || !isset($userDoc->data()['welcomeEmailSent'])) {
                Mail::to($request->email)
                    ->bcc(config('mail.admin.to'))
                    ->send(new WelcomeMail($request->email));

                $userDocRef->set([
                    'email' => $request->email,
                    'welcomeEmailSent' => true,
                    'createdAt' => \Carbon\Carbon::now()->toIso8601String(),
                ], ['merge' => true]);

                error_log('CHECKPOINT_REGISTER_EMAIL_SENT: Welcome email sent to new user: ' . $request->email . ', user_id: ' . $userId);
            }

            error_log('CHECKPOINT_REGISTER_SUCCESS: Registration successful for user_id: ' . $userId . ', email: ' . $request->email);
            return redirect()->route('login')->with('success', 'Registration successful! You can now log in.');
        } catch (AuthException $e) {
            error_log('CHECKPOINT_REGISTER_FAILED: Registration error for email: ' . $request->email . ', error: ' . $e->getMessage());
            return back()->withErrors(['error' => 'An error occurred during registration: ' . $e->getMessage()]);
        } catch (\Exception $e) {
            error_log('CHECKPOINT_REGISTER_UNEXPECTED: Unexpected registration error for email: ' . $request->email . ', error: ' . $e->getMessage());
            return back()->withErrors(['error' => 'An unexpected error occurred during registration. Please try again later.']);
        }
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|min:6',
        ]);

        try {
            error_log('CHECKPOINT_LOGIN_ATTEMPT: Login attempt for email: ' . $request->email . ', ip: ' . $request->ip());
            $signInResult = $this->auth->signInWithEmailAndPassword($request->email, $request->password);
            error_log('CHECKPOINT_LOGIN_FIREBASE_AUTH: Firebase auth successful for email: ' . $request->email);
            $firebaseUserId = $signInResult->firebaseUserId();
            $idToken = $signInResult->idToken();

            session([
                'firebase_user' => $firebaseUserId,
                'firebase_token' => $idToken,
            ]);
            error_log('CHECKPOINT_LOGIN_SESSION_SET: Session set for email: ' . $request->email . ', user_id: ' . $firebaseUserId);

            // Call the function to check subscription expiry
            error_log('CHECKPOINT_LOGIN_FORM: calling function for expiry stuff ' . request()->ip());
            $this->checkSubscriptionExpiry($firebaseUserId, $request->email);

            error_log('CHECKPOINT_LOGIN_SUCCESS: Login successful for email: ' . $request->email . ', user_id: ' . $firebaseUserId);
            return redirect()->route('dashboard')->with('success', 'Login successful!');
        } catch (InvalidPassword $e) {
            error_log('CHECKPOINT_LOGIN_INVALID_PASSWORD: Invalid password for email: ' . $request->email);
            return back()->withErrors(['error' => 'The password you entered is incorrect. Please try again.']);
        } catch (UserNotFound $e) {
            error_log('CHECKPOINT_LOGIN_USER_NOT_FOUND: User not found for email: ' . $request->email);
            return back()->withErrors(['error' => 'No account found with this email. Please check the email and try again.']);
        } catch (AuthException $e) {
            error_log('CHECKPOINT_LOGIN_AUTH_ERROR: Firebase auth error for email: ' . $request->email . ', error: ' . $e->getMessage());
            $userFriendlyMessage = $this->getFriendlyErrorMessage($e->getMessage());
            return back()->withErrors(['error' => $userFriendlyMessage]);
        } catch (\Exception $e) {
            error_log('CHECKPOINT_LOGIN_UNEXPECTED: Unexpected login error for email: ' . $request->email . ', error: ' . $e->getMessage());
            return back()->withErrors(['error' => 'An unexpected error occurred during login. Please try again later.']);
        }
    }

    public function sendResetPasswordEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        try {
            error_log('CHECKPOINT_RESET_PASSWORD_REQUEST: Password reset requested for email: ' . $request->email . ', ip: ' . $request->ip());
            $this->auth->sendPasswordResetLink($request->email);
            error_log('CHECKPOINT_RESET_PASSWORD_SENT: Password reset link sent for email: ' . $request->email);
            return redirect()->route('login')->with('success', 'Password reset link sent to your email.');
        } catch (UserNotFound $e) {
            error_log('CHECKPOINT_RESET_PASSWORD_NOT_FOUND: User not found for email: ' . $request->email);
            return back()->withErrors(['error' => 'No account found with this email. Please check the email and try again.']);
        } catch (AuthException $e) {
            error_log('CHECKPOINT_RESET_PASSWORD_ERROR: Password reset error for email: ' . $request->email . ', error: ' . $e->getMessage());
            return back()->withErrors(['error' => 'An error occurred while sending the password reset link: ' . $e->getMessage()]);
        } catch (\Exception $e) {
            error_log('CHECKPOINT_RESET_PASSWORD_UNEXPECTED: Unexpected password reset error for email: ' . $request->email . ', error: ' . $e->getMessage());
            return back()->withErrors(['error' => 'An unexpected error occurred while sending the password reset link. Please try again later.']);
        }
    }

    public function logout()
    {
        error_log('CHECKPOINT_LOGOUT: User logged out, session: ' . json_encode(session()->all()) . ', ip: ' . request()->ip());
        session()->flush();
        session()->regenerate();
        return redirect()->route('login')->with('success', 'Logged out successfully.');
    }

    public function getFirebaseConfig()
    {
        // try {
        //     $filePath = storage_path('firebase/google-services.json');
        //     error_log('CHECKPOINT_FIREBASE_CONFIG_REQUEST: Config requested, ip: ' . request()->ip());
        //     if (!file_exists($filePath)) {
        //         error_log('CHECKPOINT_FIREBASE_CONFIG_NOT_FOUND: File not found at ' . $filePath);
        //         return response()->json(['error' => 'Firebase credentials file not found'], 404);
        //     }

        //     $firebaseConfig = json_decode(file_get_contents($filePath), true);
        //     if (json_last_error() !== JSON_ERROR_NONE) {
        //         error_log('CHECKPOINT_FIREBASE_CONFIG_INVALID: Invalid JSON format in file ' . $filePath);
        //         return response()->json(['error' => 'Invalid JSON format in Firebase credentials'], 400);
        //     }

        //     $apiKey = $firebaseConfig['client'][0]['api_key'][0]['current_key'];
        //     $projectId = $firebaseConfig['project_info']['project_id'];
        //     $authDomain = request()->getHost();
        //     $storageBucket = $firebaseConfig['project_info']['storage_bucket'];
        //     $messagingSenderId = $firebaseConfig['project_info']['project_number'];
        //     $appId = $firebaseConfig['client'][0]['client_info']['mobilesdk_app_id'];

        //     error_log('CHECKPOINT_FIREBASE_CONFIG_SUCCESS: Config retrieved, domain: ' . $authDomain . ', project_id: ' . $projectId);

        //     return response()->json([
        //         'apiKey' => $apiKey,
        //         'authDomain' => "{$projectId}.firebaseapp.com",
        //         'projectId' => $projectId,
        //         'storageBucket' => $storageBucket,
        //         'messagingSenderId' => $messagingSenderId,
        //         'appId' => $appId,
        //     ], 200);
        // } catch (\Exception $e) {
        //     error_log('CHECKPOINT_FIREBASE_CONFIG_ERROR: Failed to load config, error: ' . $e->getMessage());
        //     return response()->json(['error' => 'Failed to load Firebase config: ' . $e->getMessage()], 500);
        // }
    }

    public function handleGoogleCallback(Request $request)
    {
        try {
            $userData = $request->input('user');
            error_log('CHECKPOINT_GOOGLE_CALLBACK: Google callback received, user_data: ' . json_encode($userData) . ', ip: ' . $request->ip());

            if (!$userData || !isset($userData['uid'])) {
                error_log('CHECKPOINT_GOOGLE_INVALID_DATA: Invalid user data, received: ' . json_encode($userData) . ', ip: ' . $request->ip());
                return response()->json(['error' => 'Invalid user data'], 400);
            }

            $userId = $userData['uid'];
            $userEmail = $userData['email'] ?? null;
            error_log('CHECKPOINT_GOOGLE_USER_ID: User ID extracted, user_id: ' . $userId . ', email: ' . ($userEmail ?? 'none'));

            // Set session data
            session([
                'firebase_user' => $userId,
                'user_email' => $userEmail,
                'user_name' => $userData['displayName'] ?? null,
            ]);
            error_log('CHECKPOINT_GOOGLE_SESSION_SET: Session set for user_id: ' . $userId . ', email: ' . ($userEmail ?? 'none'));

            // Save user data to Firestore
            $userDocRef = $this->firestore->database()->collection('Users')->document($userId);
            $userDoc = $userDocRef->snapshot();

            if (!$userDoc->exists()) {
                $userDataToSave = [
                    'email' => $userEmail,
                    'name' => $userData['displayName'] ?? null,
                    'photoUrl' => $userData['photoURL'] ?? null,
                    'provider' => 'google.com',
                    'createdAt' => Carbon::now()->toIso8601String(),
                    'lastLoginAt' => Carbon::now()->toIso8601String(),
                    'welcomeEmailSent' => false
                ];

                // Try to send welcome email, but don't let it fail the registration
                if ($userEmail) {
                    try {
                        Mail::to($userEmail)
                            ->bcc(config('mail.admin.to'))
                            ->send(new WelcomeMail($userEmail));
                        $userDataToSave['welcomeEmailSent'] = true;
                        error_log('CHECKPOINT_GOOGLE_EMAIL_SENT: Welcome email sent to new Google user, email: ' . $userEmail);
                    } catch (\Exception $emailError) {
                        error_log('CHECKPOINT_GOOGLE_EMAIL_ERROR: Failed to send welcome email: ' . $emailError->getMessage());
                        // Continue with registration even if email fails
                        $userDataToSave['welcomeEmailSent'] = false;
                    }
                }

                $userDocRef->set($userDataToSave);
                error_log('CHECKPOINT_GOOGLE_USER_CREATED: New user created in Firestore, user_id: ' . $userId);
            } else {
                // Update last login time for existing user
                $userDocRef->set([
                    'lastLoginAt' => Carbon::now()->toIso8601String()
                ], ['merge' => true]);
            }

            // Call the function to check subscription expiry
            if ($userEmail) {
                $this->checkSubscriptionExpiry($userId, $userEmail);
            }

            error_log('CHECKPOINT_GOOGLE_SUCCESS: Google login successful, user_id: ' . $userId . ', email: ' . ($userEmail ?? 'none'));
            return response()->json([
                'success' => true,
                'redirect' => route('dashboard')
            ]);

        } catch (\Exception $e) {
            error_log('CHECKPOINT_GOOGLE_ERROR: Google callback error: ' . $e->getMessage() . ', user_data: ' . json_encode($request->input('user') ?? 'none') . ', ip: ' . $request->ip());
            return response()->json(['error' => 'Authentication failed: ' . $e->getMessage()], 500);
        }
    }

    // New endpoint to log client-side checkpoints
    public function logCheckpoint(Request $request)
    {
        $request->validate([
            'checkpoint' => 'required|string',
            'email' => 'nullable|email',
        ]);

        $checkpoint = $request->input('checkpoint');
        $email = $request->input('email', 'unknown');
        error_log("CHECKPOINT_CLIENT_{$checkpoint}: Client checkpoint reached, email: {$email}, ip: " . $request->ip());
        return response()->json(['status' => 'logged']);
    }

    private function getFriendlyErrorMessage($errorCode)
    {
        switch ($errorCode) {
            case 'INVALID_LOGIN_CREDENTIALS':
                return 'Invalid login credentials. Please try again';
            default:
                return 'An error occurred. Please try again.';
        }
    }

    private function checkSubscriptionExpiry($userId, $email)
    {
        try {
            error_log("CHECKPOINT_SUBSCRIPTION_CHECK: Checking subscription expiry for user_id: $userId, email: " . ($email ?? 'none'));
            $investorDocRef = $this->firestore->database()->collection('InvestorApplication')->document($userId);
            $investorDoc = $investorDocRef->snapshot();
    
            if ($investorDoc->exists()) {
                $data = $investorDoc->data();
                if (isset($data['subscriptionExpiry'])) {
                    $expiry = $data['subscriptionExpiry'];
                    $expiryDate = Carbon::createFromTimestamp($expiry->get()->getTimestamp());
                    $daysLeft = Carbon::now()->diffInDays($expiryDate, false);
    
                    if ($daysLeft < 5) {
                        $today = Carbon::today()->toDateString();
                        $logFile = storage_path("logs/subscription_emails.log");
    
                        // Check if an email has already been sent today
                        $logContent = file_exists($logFile) ? file_get_contents($logFile) : '';
                        if (strpos($logContent, "user_id: $userId, date: $today") === false) {
                            if ($email) {
                                $name = $data['firstName'] . ' ' . $data['secondName'] ?? 'User';
                                $subscriptionMessage = $daysLeft >= 0 
                                    ? "Your subscription is ending in $daysLeft days. Please renew it in the Investor Application form."
                                    : "Your subscription has already expired. Please renew it in the Investor Application form.";
                            
                                error_log("CHECKPOINT_CONTROLLER_MESSAGE: " . gettype($subscriptionMessage) . " - " . $subscriptionMessage);
                            
                                Mail::to($email)->send(new \App\Mail\SubscriptionExpiryReminder($name, $subscriptionMessage));
                            } else {
                                error_log("CHECKPOINT_SUBSCRIPTION_NO_EMAIL: No email found for user_id: $userId");
                            }
                        } else {
                            error_log("CHECKPOINT_SUBSCRIPTION_EMAIL_ALREADY_SENT: Email already sent today for user_id: $userId");
                        }
                    } else {
                        error_log("CHECKPOINT_SUBSCRIPTION_NOT_DUE: Subscription not expiring in less than 5 days for user_id: $userId, expiry: " . $expiryDate->toIso8601String());
                    }
                } else {
                    error_log("CHECKPOINT_SUBSCRIPTION_NO_EXPIRY: No subscriptionExpiry field for user_id: $userId");
                }
            } else {
                error_log("CHECKPOINT_SUBSCRIPTION_NO_INVESTOR: No InvestorApplication for user_id: $userId");
            }
        } catch (\Exception $e) {
            error_log("CHECKPOINT_SUBSCRIPTION_ERROR: Error checking subscription for user_id: $userId, error: " . $e->getMessage());
        }
    }
}