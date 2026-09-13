<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invitation $invitation,
        public string $rawToken,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Você foi convidado para o OneFisc',
        );
    }

    public function content(): Content
    {
        $acceptPath = "/api/invitations/{$this->rawToken}/accept";
        $inviteeName = e($this->invitation->name);
        $accountName = e($this->invitation->account->name);

        return new Content(
            htmlString: <<<HTML
                <p>Olá {$inviteeName},</p>
                <p>Você foi convidado a participar da conta {$accountName} no OneFisc.</p>
                <p>Use o token abaixo para aceitar o convite (válido por 7 dias):</p>
                <p><code>{$this->rawToken}</code></p>
                <p>Envie uma requisição POST para {$acceptPath} informando sua senha.</p>
                HTML,
        );
    }
}
