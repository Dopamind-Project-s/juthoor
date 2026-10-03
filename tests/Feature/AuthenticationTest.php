<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a guest can sign in and sign out through the Arabic session flow', function () {
    $user = User::factory()->create(['password' => 'secret-password']);

    $this->get(route('management.families.index'))->assertRedirect(route('login'));
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'secret-password'])
        ->assertRedirect(route('management.families.index'));
    $this->assertAuthenticatedAs($user);

    $this->post(route('logout'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('invalid credentials return an Arabic validation message', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors(['email' => 'بيانات الدخول غير صحيحة.']);

    $this->assertGuest();
});

test('repeated invalid sign in attempts are rate limited', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
    }

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors(['email' => 'محاولات كثيرة. حاول مجددًا بعد دقيقة.']);
});
