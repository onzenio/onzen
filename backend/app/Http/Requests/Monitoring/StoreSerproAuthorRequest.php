<?php

namespace App\Http\Requests\Monitoring;

use App\Models\SerproRequestAuthor;
use Illuminate\Foundation\Http\FormRequest;

class StoreSerproAuthorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', SerproRequestAuthor::class) === true;
    }

    /**
     * Normalize the document to digits before validating so the contract is
     * the same for formatted and unformatted input.
     */
    protected function prepareForValidation(): void
    {
        $document = $this->input('document');

        if (is_string($document)) {
            $this->merge(['document' => preg_replace('/\D/', '', $document)]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'document' => ['required', 'string', 'regex:/^(?:\d{11}|\d{14})$/'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
        ];
    }
}
