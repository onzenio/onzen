<?php

namespace App\Http\Requests\Admin\Serpro;

use App\Models\User;
use App\Services\Monitoring\SerproAdminService;
use App\Services\Monitoring\SerproTransportGate;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SwitchSerproEnvironmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-serpro') === true;
    }

    /**
     * Produção exige dupla confirmação explícita e evidência; homologação
     * alterna sem cerimônia.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $production = $this->input('environment') === 'producao';

        return [
            'environment' => ['required', 'string', Rule::in(SerproTransportGate::ENVIRONMENTS)],
            'confirm_environment' => [Rule::when($production, ['accepted'])],
            'confirm_impact' => [Rule::when($production, ['accepted'])],
            'evidence' => [Rule::requiredIf($production), 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * A tentativa recusada vira Audit antes de responder 422; nenhum valor
     * sensível entra no metadata (apenas ambiente pretendido e motivos).
     */
    protected function failedValidation(Validator $validator): void
    {
        $requested = $this->input('environment');
        $actor = $this->user();

        app(SerproAdminService::class)->recordEnvironmentRefusal(
            $actor instanceof User ? $actor : null,
            is_string($requested) ? $requested : null,
            array_keys($validator->errors()->toArray()),
        );

        parent::failedValidation($validator);
    }
}
