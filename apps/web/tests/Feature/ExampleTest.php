<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_install_sends_visitors_to_registration()
    {
        $this->get(route('home'))->assertRedirect(route('register'));
    }

    public function test_existing_install_sends_visitors_to_login()
    {
        User::factory()->create();
        $this->get(route('home'))->assertRedirect(route('login'));
    }

    public function test_signed_in_users_go_to_dashboard()
    {
        $this->actingAs(User::factory()->create())->get(route('home'))->assertRedirect(route('dashboard'));
    }
}
