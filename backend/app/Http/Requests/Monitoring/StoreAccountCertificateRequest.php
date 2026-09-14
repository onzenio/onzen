<?php

namespace App\Http\Requests\Monitoring;

use App\Models\AccountCertificate;
use Illuminate\Foundation\Http\FormRequest;

class StoreAccountCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AccountCertificate::class) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'certificate' => ['required', 'file', 'max:2048'],
            'certificate_password' => ['present', 'nullable', 'string', 'max:500'],
        ];
    }
}
