<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Auth;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Exception\Auth\InvalidPassword;
use Kreait\Firebase\Exception\Auth\UserNotFound;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $auth;

    protected function setUp(): void
    {
        parent::setUp();

        $factory = (new Factory)->withServiceAccount(config('firebase.credentials'));
        $this->auth = $factory->createAuth();
    }

    /** @test */
    public function it_shows_the_login_form()
    {
        $response = $this->get(route('login'));

        $response->assertStatus(200);
        $response->assertViewIs('login');
    }

    /** @test */
    public function it_shows_the_register_form()
    {
        $response = $this->get(route('register'));

        $response->assertStatus(200);
        $response->assertViewIs('register');
    }

    /** @test */
    public function it_registers_a_user()
    {
        $this->withoutExceptionHandling();
    
        $response = $this->post(route('register'), [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);
    
        $response->assertRedirect(route('login'));
        $response->assertSessionHas('success', 'Registration successful! You can now log in.');
    }

    /** @test */
    public function it_handles_registration_errors()
    {
        $this->withoutExceptionHandling();

        $response = $this->post(route('register'), [
            'email' => 'invalid-email',
            'password' => 'short',
        ]);

        $response->assertSessionHasErrors(['email', 'password']);
    }

    /** @test */
    public function it_logs_in_a_user()
    {
        $this->withoutExceptionHandling();

        $this->auth->createUserWithEmailAndPassword('test@example.com', 'password123');

        $response = $this->post(route('login'), [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('success', 'Login successful!');
        $this->assertTrue(Session::has('firebase_user'));
        $this->assertTrue(Session::has('firebase_token'));
    }

    /** @test */
    public function it_handles_invalid_password()
    {
        $this->withoutExceptionHandling();

        $this->auth->createUserWithEmailAndPassword('test@example.com', 'password123');

        $response = $this->post(route('login'), [
            'email' => 'test@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertSessionHasErrors(['error' => 'Invalid password. Please try again.']);
    }

    /** @test */
    public function it_handles_user_not_found()
    {
        $this->withoutExceptionHandling();
    
        $response = $this->post(route('login'), [
            'email' => 'nonexistent@example.com',
            'password' => 'password123',
        ]);
    
        $response->assertSessionHasErrors(['error' => 'No account found with this email.']);
    }

    /** @test */
    public function it_sends_reset_password_email()
    {
        $this->withoutExceptionHandling();

        $this->auth->createUserWithEmailAndPassword('test@example.com', 'password123');

        $response = $this->post(route('password.reset'), [
            'email' => 'test@example.com',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('success', 'Password reset link sent to your email.');
    }

    /** @test */
    public function it_handles_reset_password_email_errors()
    {
        $this->withoutExceptionHandling();

        $response = $this->post(route('password.reset'), [
            'email' => 'nonexistent@example.com',
        ]);

        $response->assertSessionHasErrors(['error' => 'No account found with this email.']);
    }

    /** @test */
    public function it_logs_out_a_user()
    {
        $this->withoutExceptionHandling();

        Session::put('firebase_user', 'some-user-id');
        Session::put('firebase_token', 'some-token');

        $response = $this->get(route('logout'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('success', 'Logged out successfully.');
        $this->assertFalse(Session::has('firebase_user'));
        $this->assertFalse(Session::has('firebase_token'));
    }

    /** @test */
    // public function it_handles_google_callback()
    // {
    //     $this->withoutExceptionHandling();

    //     $userData = [
    //         'uid' => 'some-user-id',
    //         'email' => 'test@example.com',
    //         'displayName' => 'Test User',
    //     ];

    //     $response = $this->post(route('auth.google.callback'), ['user' => $userData]);

    //     $response->assertJson([
    //         'success' => true,
    //         'redirect' => route('dashboard'),
    //     ]);
    //     $this->assertTrue(Session::has('firebase_user'));
    //     $this->assertTrue(Session::has('user_email'));
    //     $this->assertTrue(Session::has('user_name'));
    // }

    /** @test */
    // public function it_handles_google_callback_errors()
    // {
    //     $this->withoutExceptionHandling();

    //     Log::shouldReceive('error')->once();

    //     $response = $this->post(route('auth.google.callback'), ['user' => null]);

    //     $response->assertJson(['error' => 'Invalid user data']);
    // }
}