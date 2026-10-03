<?php

use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\FamilyMembership;
use App\Models\User;
use App\Notifications\FamilyInvitationNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

/** @return array{family: Family, manager: User} */
function invitationContext(): array
{
    $family = Family::query()->create(['name_ar' => 'عائلة الدعوة', 'slug' => 'invited-family']);
    $family->familyTrees()->create(['name_ar' => 'الشجرة', 'slug' => 'main', 'is_primary' => true]);
    $manager = User::factory()->create();
    FamilyMembership::query()->create(['family_id' => $family->id, 'user_id' => $manager->id, 'role' => 'admin']);

    return compact('family', 'manager');
}

function sendFamilyInvitation($case, array $context, string $email = 'invitee@example.test'): string
{
    Notification::fake();
    $case->actingAs($context['manager'])->post(route('invitations.store', $context['family']->slug), [
        'email' => $email, 'role' => 'editor',
    ])->assertSessionHasNoErrors();
    $token = '';
    Notification::assertSentOnDemand(FamilyInvitationNotification::class, function ($notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    return $token;
}

test('an existing verified user can accept an invitation once and only with the invited email', function () {
    $context = invitationContext();
    $token = sendFamilyInvitation($this, $context);
    $invitee = User::factory()->create(['email' => 'invitee@example.test']);
    $other = User::factory()->create();

    $this->actingAs($other)->post(route('invitations.accept', $token))->assertSessionHasErrors(['invitation']);
    $this->actingAs($invitee)->post(route('invitations.accept', $token))->assertRedirect();
    $this->actingAs($invitee)->post(route('invitations.accept', $token))->assertSessionHasErrors(['invitation']);

    expect($invitee->roleFor($context['family']))->toBe('editor');
});

test('expired and cancelled invitations cannot be accepted', function () {
    $context = invitationContext();
    $expiredToken = sendFamilyInvitation($this, $context, 'expired@example.test');
    FamilyInvitation::query()->where('email', 'expired@example.test')->update(['expires_at' => now()->subMinute()]);
    $expiredUser = User::factory()->create(['email' => 'expired@example.test']);
    $this->actingAs($expiredUser)->post(route('invitations.accept', $expiredToken))->assertSessionHasErrors(['invitation']);

    $cancelledToken = sendFamilyInvitation($this, $context, 'cancelled@example.test');
    $invitation = FamilyInvitation::query()->where('email', 'cancelled@example.test')->sole();
    $this->actingAs($context['manager'])->delete(route('invitations.cancel', [$context['family']->slug, $invitation->id]))->assertSessionHasNoErrors();
    $cancelledUser = User::factory()->create(['email' => 'cancelled@example.test']);
    $this->actingAs($cancelledUser)->post(route('invitations.accept', $cancelledToken))->assertSessionHasErrors(['invitation']);
});

test('a new user registers only through the invitation and accepts it', function () {
    $context = invitationContext();
    $token = sendFamilyInvitation($this, $context, 'new@example.test');
    auth()->logout();

    $this->post(route('invitations.register.store', $token), [
        'name' => 'مستخدم جديد', 'password' => 'a-secure-password', 'password_confirmation' => 'a-secure-password',
    ])->assertRedirect(route('invitations.show', $token));
    $user = User::query()->where('email', 'new@example.test')->sole();
    $this->post(route('invitations.accept', $token))->assertRedirect();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue()->and($user->roleFor($context['family']))->toBe('editor');
});

test('email verification and password reset notifications use the configured mail channel', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();
    $this->actingAs($user)->post(route('verification.send'))->assertSessionHasNoErrors();
    Notification::assertSentTo($user, VerifyEmail::class);
    $verificationUrl = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), ['id' => $user->id, 'hash' => sha1($user->email)]);
    $this->actingAs($user)->get($verificationUrl)->assertRedirect(route('management.families.index'));
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();

    auth()->logout();
    $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();
    $resetToken = '';
    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$resetToken): bool {
        $resetToken = $notification->token;

        return true;
    });
    $this->post(route('password.update'), [
        'token' => $resetToken, 'email' => $user->email,
        'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password',
    ])->assertRedirect(route('login'));
});
