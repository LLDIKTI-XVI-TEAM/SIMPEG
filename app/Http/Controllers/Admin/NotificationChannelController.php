<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Notifications\CreateNotificationChannelAction;
use App\Actions\Notifications\DeleteNotificationChannelAction;
use App\Actions\Notifications\SetNotificationChannelEnabledAction;
use App\Actions\Notifications\SetNotificationEventChannelPolicyAction;
use App\Actions\Notifications\ShowNotificationChannelConfigAction;
use App\Actions\Notifications\UpdateNotificationChannelAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\DeleteNotificationChannelRequest;
use App\Http\Requests\Notifications\SetNotificationChannelEnabledRequest;
use App\Http\Requests\Notifications\SetNotificationEventChannelPolicyRequest;
use App\Http\Requests\Notifications\StoreNotificationChannelRequest;
use App\Http\Requests\Notifications\UpdateNotificationChannelRequest;
use App\Models\RefNotificationChannel;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class NotificationChannelController extends Controller
{
    public function index(ShowNotificationChannelConfigAction $action): View
    {
        return view('admin.data-master.channel-notifikasi', $action->execute());
    }

    public function store(StoreNotificationChannelRequest $request, CreateNotificationChannelAction $action): RedirectResponse
    {
        $action->execute($request->validated(), $request);

        return back()->with('success', 'Channel notifikasi berhasil ditambahkan dalam keadaan nonaktif.');
    }

    public function update(
        UpdateNotificationChannelRequest $request,
        RefNotificationChannel $notificationChannel,
        UpdateNotificationChannelAction $action,
    ): RedirectResponse {
        $action->execute($notificationChannel->id, $request->validated('name'), $request);

        return back()->with('success', 'Nama channel notifikasi berhasil diperbarui.');
    }

    public function destroy(
        DeleteNotificationChannelRequest $request,
        RefNotificationChannel $notificationChannel,
        DeleteNotificationChannelAction $action,
    ): RedirectResponse {
        $action->execute($notificationChannel->id, $request);

        return back()->with('success', 'Channel notifikasi berhasil dihapus.');
    }

    public function setEnabled(
        SetNotificationChannelEnabledRequest $request,
        RefNotificationChannel $notificationChannel,
        SetNotificationChannelEnabledAction $action,
    ): RedirectResponse {
        $channel = $action->execute(
            $notificationChannel->id,
            $request->boolean('is_enabled'),
            $request,
        );

        return back()->with(
            'success',
            $channel->is_enabled
                ? 'Channel notifikasi berhasil diaktifkan.'
                : 'Channel notifikasi berhasil dinonaktifkan.',
        );
    }

    public function setEventPolicy(
        SetNotificationEventChannelPolicyRequest $request,
        RefNotificationChannel $notificationChannel,
        SetNotificationEventChannelPolicyAction $action,
    ): RedirectResponse {
        $action->execute(
            $notificationChannel->id,
            $request->validated('event_key'),
            $request->boolean('is_enabled'),
            $request,
        );

        return back()->with('success', 'Kebijakan channel untuk event berhasil diperbarui.');
    }
}
