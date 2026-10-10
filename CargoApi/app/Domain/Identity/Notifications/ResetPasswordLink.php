<?php

declare(strict_types=1);

namespace App\Domain\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordLink extends Notification
{
    public function __construct(private readonly string $token) {}

    /** @return string[] */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Reset your Cargo Rush password')
            ->greeting('Reset your password')
            ->line('Somebody asked to reset the password for this address. If that was you, use the button below.')
            ->action('Choose a new password', $this->url($notifiable))
            ->line("This link stops working in {$minutes} minutes.")
            ->line('If you did not ask for this, nothing has changed and you can ignore this email.');
    }

    private function url(object $notifiable): string
    {
        $base = rtrim((string) config('app.frontend_url'), '/');

        return sprintf(
            '%s/reset-password?token=%s&email=%s',
            $base,
            $this->token,
            urlencode($notifiable->getEmailForPasswordReset()),
        );
    }
}
