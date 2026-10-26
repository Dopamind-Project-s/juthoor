<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * In-app notification (database channel only). It carries a short title, a context line and a link inside the
 * platform; announcement texts and contact details are never copied into it or sent by e-mail.
 */
class CommunityNotification extends Notification
{
    public function __construct(
        public string $kind,
        public string $title,
        public string $body,
        public ?string $url = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, ?string> */
    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, 'title' => $this->title, 'body' => $this->body, 'url' => $this->url];
    }
}
