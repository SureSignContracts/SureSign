<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFinalAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Tenant isolation enforced in controller
    }

    public function rules(): array
    {
        return [
            'contract_id'      => 'nullable|integer|exists:contracts,id',
            'trade_package_id' => 'nullable|integer|exists:trade_packages,id',
            'notes'            => 'nullable|string|max:5000',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $hasContract  = !empty($this->input('contract_id'));
            $hasPackage   = !empty($this->input('trade_package_id'));

            if ($hasContract && $hasPackage) {
                $validator->errors()->add('contract_id', 'A Final Account must reference either a contract or a trade package — not both.');
            }

            if (!$hasContract && !$hasPackage) {
                $validator->errors()->add('contract_id', 'A Final Account must reference either a contract or a trade package.');
            }
        });
    }
}
