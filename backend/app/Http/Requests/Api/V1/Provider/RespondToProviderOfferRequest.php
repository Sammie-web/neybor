<?php

namespace App\Http\Requests\Api\V1\Provider;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RespondToProviderOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::ServiceProvider;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['accept', 'reject'])],
        ];
    }
}
