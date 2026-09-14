<?php

namespace App\Http\Requests\Admin\Serpro;

use App\Services\Monitoring\SerproTransportGate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSerproCredentialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-serpro') === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'environment' => ['required', 'string', Rule::in(SerproTransportGate::ENVIRONMENTS)],
            'client_id' => ['required', 'string', 'max:500'],
            'consumer_secret' => ['required', 'string', 'max:2000'],
            'contratante_doc' => ['nullable', 'string', 'max:32'],
            'certificate' => ['nullable', 'string', 'max:2000000', 'required_with:certificate_password'],
            'certificate_password' => ['nullable', 'string', 'max:2000', 'required_with:certificate'],
        ];
    }
}
