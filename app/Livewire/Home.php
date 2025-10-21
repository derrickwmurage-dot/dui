<?php

namespace App\Livewire;

use Livewire\Component;
use Mary\Traits\Toast;
use Illuminate\Support\Facades\Mail;
use App\Mail\ContactFormMail;

class Home extends Component
{
    use Toast;
    public bool $contactModal = false;

    public $first_name, $last_name, $email, $company_name, $message;

    public function mount()
    {
        if (session()->has('success')) {
            $this->success("Login successful! Welcome");
        }
    }

    public function openContactPage()
    {
        $this->contactModal = true;
    } 

    public function closeContactPage()
    {
        $this->contactModal = false;
    }

    public function sendContactForm()
    {
        $this->validate([
            'first_name' => 'required',
            'last_name' => 'required',
            'email' => 'required|email',
            'company_name' => 'required',
            'message' => 'required',
        ]);

        $contactData = [
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'company_name' => $this->company_name,
            'message' => $this->message,
        ];

        Mail::to('info@duitechnology.com')
        ->bcc(config('mail.admin.to'))
        ->send(new ContactFormMail($contactData));

        $this->resetForm();
        $this->closeContactPage();
        $this->success('Your message has been sent successfully!');
    }

    private function resetForm()
    {
        $this->first_name = '';
        $this->last_name = '';
        $this->email = '';
        $this->company_name = '';
        $this->message = '';
    }

    public function render()
    {
        return view('livewire.home');
    }
}