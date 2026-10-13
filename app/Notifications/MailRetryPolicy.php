<?php

namespace App\Notifications;

trait MailRetryPolicy
{
    public int $tries = 4;

    public int $timeout = 30;

    public function backoff(): array
    {
        return [30, 120, 300];
    }
}
