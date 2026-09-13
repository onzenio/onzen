<?php

namespace App\Http\Requests\Monitoring;

use App\Models\MonitoringEnrollment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Only `configuration` is editable through the API; status transitions have
 * their own model operations and lifecycle commands.
 */
class UpdateMonitoringEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $enrollment = $this->route('enrollment');

        return $enrollment instanceof MonitoringEnrollment
            && $this->user()?->can('update', $enrollment) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'configuration' => ['required', 'array'],
        ];
    }
}
