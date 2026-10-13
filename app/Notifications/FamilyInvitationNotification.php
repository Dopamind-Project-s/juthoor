<?php

namespace App\Notifications;

use App\Models\FamilyInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FamilyInvitationNotification extends Notification implements ShouldQueue
{
    use MailRetryPolicy, Queueable;

    public function __construct(public FamilyInvitation $invitation, public string $token)
    {
        $this->afterCommit();
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $current = $this->invitation->fresh();

        return $current && $current->isAcceptable() && hash_equals($current->token_hash, hash('sha256', $this->token));
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('دعوة للانضمام إلى '.$this->invitation->family->name_ar)
            ->greeting('مرحبًا')
            ->line('دُعيت للانضمام إلى '.$this->invitation->family->name_ar.' ضمن منصة جذور.')
            ->action('عرض الدعوة', route('invitations.show', $this->token))
            ->line('تنتهي صلاحية الدعوة في '.$this->invitation->expires_at->format('Y-m-d H:i').'.');
    }
}
