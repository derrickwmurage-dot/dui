<?php

namespace App\Livewire\Profile;

use Livewire\Component;

class PrivacyPolicy extends Component
{
    public function redirectToProfile()
    {
        return redirect()->route('profile');
    }
    public function render()
    {
        return view('livewire.profile.privacy-policy');
    }
}
