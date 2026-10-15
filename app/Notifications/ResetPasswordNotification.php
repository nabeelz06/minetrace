<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ResetPasswordNotification — email reset password bermerek MINETRACE.
 *
 * Mengganti notifikasi default Laravel (yang mengarah ke route Blade)
 * agar link mengarah ke halaman Inertia/Vue kita sendiri.
 */
class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $token)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        return (new MailMessage)
            ->subject('Reset Password — MINETRACE')
            ->greeting("Halo, {$notifiable->name}")
            ->line('Kami menerima permintaan untuk mereset password akun MINETRACE Anda.')
            ->action('Reset Password', $url)
            ->line('Link ini berlaku selama 60 menit sejak email ini dikirim.')
            ->line('Jika Anda tidak meminta reset password, abaikan saja email ini — password Anda tidak akan berubah.')
            ->salutation('Salam, Tim MINETRACE — PT Timah Tbk');
    }
}
