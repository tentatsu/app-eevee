<?php

namespace App\Mail;

use App\Models\App;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AppUserPasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public App $app, public string $token, public int $ttlMinutes) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '['.$this->app->name.'] パスワードの再設定');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.app-user-password-reset');
    }
}
