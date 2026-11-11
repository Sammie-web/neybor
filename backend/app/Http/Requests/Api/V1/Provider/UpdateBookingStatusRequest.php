<?php

namespace App\Http\Requests\Api\V1\Provider;

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBookingStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::ServiceProvider;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                BookingStatus::Accepted->value,
                BookingStatus::EnRoute->value,
                BookingStatus::Arrived->value,
                BookingStatus::InProgress->value,
                BookingStatus::Completed->value,
            ])],
            'quote_amount' => [
                'required_if:status,'.BookingStatus::Completed->value,
                'prohibited_unless:status,'.BookingStatus::Completed->value,
                'numeric',
                'min:0',
                'max:99999999.99',
            ],
        ];
    }
}
