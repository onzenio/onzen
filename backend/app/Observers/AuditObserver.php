<?php

namespace App\Observers;

use App\Models\Account;
use App\Models\Client;
use App\Models\Invitation;
use App\Models\Plan;
use App\Models\User;
use App\Services\AuditService;
use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class AuditObserver
{
    public function __construct(protected AuditService $audit) {}

    /**
     * @param  Account|User|Invitation|Plan|Client  $model
     */
    public function created(Model $model): void
    {
        $this->write($model, 'created');
    }

    /**
     * @param  Account|User|Invitation|Plan|Client  $model
     */
    public function updated(Model $model): void
    {
        $this->write($model, 'updated');
    }

    /**
     * @param  Account|User|Invitation|Plan|Client  $model
     */
    public function deleted(Model $model): void
    {
        $this->write($model, 'deleted');
    }

    protected function write(Model $model, string $verb): void
    {
        try {
            $actor = auth()->user();
            $actor = $actor instanceof User ? $actor : null;

            // Account é o próprio tenant (alvo = ela mesma); Plan não tem
            // account_id (alvo = null); demais têm account_id próprio.
            $target = $model instanceof Account
                ? $model->getKey()
                : $model->getAttribute('account_id');
            $target = is_int($target) ? $target : null;

            $origin = CurrentAccount::get() ?? $actor?->account_id ?? $target;

            $entity = strtolower(class_basename($model));

            $this->audit->record($actor, $origin, $target, "{$entity}.{$verb}", [
                'id' => $model->getKey(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('audit.observer_failed', [
                'model' => $model::class,
                'id' => $model->getKey(),
                'event' => $verb,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
