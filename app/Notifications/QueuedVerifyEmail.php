<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class QueuedVerifyEmail extends VerifyEmail implements ShouldQueue
{
    use MailRetryPolicy, Queueable;

    public function __construct()
    {
        $this->afterCommit();
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $user = $notifiable->fresh();

        return $user && $user->is_active && ! $user->hasVerifiedEmail();
    }
}
