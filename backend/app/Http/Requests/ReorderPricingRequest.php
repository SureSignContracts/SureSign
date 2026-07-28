<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared shape for reordering any of the four reorderable pricing entities
 * (plans, feature sections, features, included items). Whether the given ID
 * list is exactly a permutation of the entity's current full ID set is
 * verified in PricingManagementService, since that depends on which table is
 * being reordered.
 */
class ReorderPricingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order'   => 'required|array|min:1',
            'order.*' => 'required|integer|distinct',
        ];
    }
}
