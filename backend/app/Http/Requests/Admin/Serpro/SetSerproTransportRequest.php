<?php

namespace App\Http\Requests\Admin\Serpro;

use App\Models\User;
use App\Services\Monitoring\SerproAdminService;
use App\Services\Monitoring\SerproTransportGate;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetSerproTransportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-serpro') === true;
    }

    /**
     * Desligar é imediato; ligar em produção exige dupla confirmação e
     * evidência. O ambiente considerado é o efetivo do gate (painel > `.env`).
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $productionOn = $this->boolean('enabled')
            && app(SerproTransportGate::class)->environment() === 'producao';

        return [
            'enabled' => ['required', 'boolean'],
            'confirm_transport' => [Rule::when($productionOn, ['accepted'])],
            'confirm_impact' => [Rule::when($productionOn, ['accepted'])],
            'evidence' => [Rule::requiredIf($productionOn), 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * A tentativa recusada vira Audit antes de responder 422; nenhum valor
     * sensível entra no metadata (apenas o intent e os motivos).
     */
    protected function failedValidation(Validator $validator): void
    {
        $actor = $this->user();

        app(SerproAdminService::class)->recordTransportRefusal(
            $actor instanceof User ? $actor : null,
            $this->boolean('enabled'),
            array_keys($validator->errors()->toArray()),
        );

        parent::failedValidation($validator);
    }
}
