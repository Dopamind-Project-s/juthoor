<?php

namespace App\Notifications;

use App\Models\FamilyInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FamilyInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(public FamilyInvitation $invitation, public string $token) {}

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
