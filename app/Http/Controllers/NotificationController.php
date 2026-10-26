<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The signed-in user's in-app notifications; opening one marks it read and follows its internal link. */
class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        return view('notifications.index', ['notifications' => $request->user()->notifications()->paginate(20)]);
    }

    public function open(Request $request, string $notification): RedirectResponse
    {
        $item = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $item->markAsRead();
        $url = $item->data['url'] ?? null;

        // Only links inside the platform are followed.
        return $url && str_starts_with($url, url('/')) ? redirect()->to($url) : redirect()->route('notifications.index');
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('status', 'عُلّمت كل الإشعارات مقروءة.');
    }
}
