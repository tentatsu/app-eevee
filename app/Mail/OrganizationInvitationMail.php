<?php

namespace App\Mail;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrganizationInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Organization $organization,
        public OrganizationInvitation $invitation,
        public string $acceptUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '['.config('app.name').'] '.$this->organization->name.' の管理画面に招待されました');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.organization-invitation');
    }
}
