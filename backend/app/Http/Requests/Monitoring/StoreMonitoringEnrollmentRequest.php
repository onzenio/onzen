<?php

namespace App\Http\Requests\Monitoring;

use App\Models\MonitoringEnrollment;
use Illuminate\Foundation\Http\FormRequest;

class StoreMonitoringEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', MonitoringEnrollment::class) === true;
    }

    /**
     * Client and definition resolution happen in the service so a
     * cross-account Client yields an indistinguishable 404.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'min:1'],
            'definition_id' => ['required', 'string', 'max:120'],
            'configuration' => ['nullable', 'array'],
        ];
    }
}
