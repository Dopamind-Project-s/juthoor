<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Password;

class QueuedPasswordReset extends ResetPassword implements ShouldQueue
{
    use MailRetryPolicy, Queueable;

    public function __construct(string $token)
    {
        parent::__construct($token);
        $this->afterCommit();
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $user = $notifiable->fresh();

        return $user && $user->is_active && Password::broker()->tokenExists($user, $this->token);
    }
}
