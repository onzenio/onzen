<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Mail\InvitationMail;
use App\Models\Account;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvitationService
{
    public function __construct(protected PlanLimitService $limits) {}

    /**
     * @param  array{name?: string, email?: string, role?: string}  $data
     */
    public function invite(Account $account, ?User $inviter, array $data): Invitation
    {
        $role = $this->resolveRole($data['role'] ?? null);
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '' || $email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'email' => ['Dados do convite inválidos. Informe nome e e-mail válidos.'],
            ]);
        }

        if (User::query()->withoutGlobalScopes()->where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => ['Este e-mail já pertence a um usuário da plataforma.'],
            ]);
        }

        if ($this->hasValidPending($account->id, $email)) {
            throw ValidationException::withMessages([
                'email' => ['Já existe um convite pendente para este e-mail nesta conta.'],
            ]);
        }

        if ($message = $this->limits->canInvite($account->refresh())) {
            throw ValidationException::withMessages(['email' => [$message]]);
        }

        $rawToken = Str::random(64);

        $invitation = DB::transaction(function () use ($account, $inviter, $name, $email, $role, $rawToken) {
            return Invitation::query()->withoutGlobalScopes()->create([
                'account_id' => $account->id,
                'name' => $name,
                'email' => $email,
                'role' => $role,
                'token_hash' => hash('sha256', $rawToken),
                'expires_at' => now()->addDays(7),
                'accepted_at' => null,
                'invited_by_user_id' => $inviter?->id,
            ]);
        });

        $invitation->setAttribute('token', $rawToken);

        Mail::to($invitation->email)->send(new InvitationMail($invitation, $rawToken));

        return $invitation;
    }

    public function accept(string $rawToken, string $password): User
    {
        if (strlen($password) < 8) {
            throw ValidationException::withMessages([
                'password' => ['A senha deve ter ao menos 8 caracteres.'],
            ]);
        }

        $invitation = Invitation::query()->withoutGlobalScopes()
            ->where('token_hash', hash('sha256', $rawToken))
            ->first();

        if ($invitation === null) {
            throw ValidationException::withMessages([
                'token' => ['Convite inválido. Solicite um novo convite.'],
            ]);
        }

        if ($invitation->accepted_at !== null) {
            throw ValidationException::withMessages([
                'token' => ['Este convite já foi utilizado. Solicite um novo convite.'],
            ]);
        }

        if ($invitation->expires_at === null || $invitation->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'token' => ['Este convite expirou. Solicite um novo convite.'],
            ]);
        }

        if ($message = $this->limits->canAccept($invitation)) {
            throw ValidationException::withMessages(['token' => [$message]]);
        }

        return DB::transaction(function () use ($invitation, $password) {
            $user = User::query()->withoutGlobalScopes()->create([
                'account_id' => $invitation->account_id,
                'name' => $invitation->name,
                'email' => $invitation->email,
                'password' => $password,
                'role' => $invitation->role,
            ]);

            $invitation->forceFill(['accepted_at' => now()])->save();

            return $user;
        });
    }

    public function revoke(Invitation $invitation): void
    {
        $invitation->delete();
    }

    protected function resolveRole(mixed $role): UserRole
    {
        $value = $role instanceof UserRole ? $role->value : (string) $role;

        $allowed = [
            UserRole::Admin->value => UserRole::Admin,
            UserRole::Operator->value => UserRole::Operator,
            UserRole::User->value => UserRole::User,
        ];

        if (! isset($allowed[$value])) {
            throw ValidationException::withMessages([
                'role' => ['Papel inválido para convite.'],
            ]);
        }

        return $allowed[$value];
    }

    protected function hasValidPending(int $accountId, string $email): bool
    {
        return Invitation::query()->withoutGlobalScopes()
            ->where('account_id', $accountId)
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->exists();
    }
}
