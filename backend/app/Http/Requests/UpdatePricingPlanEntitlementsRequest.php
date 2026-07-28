<?php

namespace App\Http\Requests;

use App\Support\Entitlements\EntitlementValueType;
use App\Support\Entitlements\Feature;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Phase G2, Stage 5 — validates a full replacement set of entitlement
 * default rows for one Pricing Plan. The editor always submits every
 * non-dormant Feature::* key at once (never a partial patch), so this
 * enforces the set is exactly complete: no missing keys, no unknown/reserved
 * keys, no duplicates, and each row's value shape matches its declared
 * Feature::valueType() and is_applicable/is_unlimited combination.
 */
class UpdatePricingPlanEntitlementsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:Super Admin|Admin route middleware enforces access
    }

    public function rules(): array
    {
        return [
            'entitlements' => ['required', 'array', 'min:1'],
            'entitlements.*.feature_key' => ['required', 'string'],
            'entitlements.*.is_applicable' => ['required', 'boolean'],
            'entitlements.*.is_unlimited' => ['required', 'boolean'],
            'entitlements.*.value' => ['nullable'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $rows = $this->input('entitlements');

            if (!is_array($rows)) {
                return;
            }

            $expectedKeys = collect(Feature::ALL)->reject(fn (string $key) => Feature::isDormant($key));
            $seenKeys = [];

            foreach ($rows as $index => $row) {
                if (!is_array($row) || !isset($row['feature_key']) || !is_string($row['feature_key'])) {
                    continue; // already reported by the base 'required'/'string' rules above
                }

                $key = $row['feature_key'];

                if (!Feature::isValid($key) || Feature::isDormant($key)) {
                    $validator->errors()->add("entitlements.{$index}.feature_key", "Unknown or reserved entitlement feature key: \"{$key}\".");
                    continue;
                }

                if (isset($seenKeys[$key])) {
                    $validator->errors()->add("entitlements.{$index}.feature_key", "Duplicate entitlement row for \"{$key}\".");
                    continue;
                }

                $seenKeys[$key] = true;

                $this->validateRow($validator, $index, $key, $row);
            }

            $missing = $expectedKeys->diff(array_keys($seenKeys));
            if ($missing->isNotEmpty()) {
                $validator->errors()->add('entitlements', 'Missing entitlement rows for: ' . $missing->implode(', ') . '.');
            }
        });
    }

    private function validateRow(Validator $validator, int|string $index, string $key, array $row): void
    {
        $isApplicable = (bool) ($row['is_applicable'] ?? false);
        $isUnlimited = (bool) ($row['is_unlimited'] ?? false);
        $value = array_key_exists('value', $row) ? $row['value'] : null;

        if (!$isApplicable) {
            if ($isUnlimited || $value !== null) {
                $validator->errors()->add("entitlements.{$index}.value", "\"{$key}\" is not applicable to this plan, so it must not be unlimited or carry a value.");
            }
            return;
        }

        if ($isUnlimited) {
            if ($value !== null) {
                $validator->errors()->add("entitlements.{$index}.value", "\"{$key}\" is marked unlimited, so it must not carry a finite value.");
            }
            return;
        }

        if ($value === null) {
            $validator->errors()->add("entitlements.{$index}.value", "A value is required for \"{$key}\".");
            return;
        }

        $valueType = Feature::valueType($key);
        $matchesType = match ($valueType) {
            EntitlementValueType::BOOLEAN => is_bool($value),
            EntitlementValueType::INTEGER => is_int($value),
            EntitlementValueType::DECIMAL => is_int($value) || is_float($value),
            EntitlementValueType::STRING, EntitlementValueType::ENUM => is_string($value),
            default => false,
        };

        if (!$matchesType) {
            $validator->errors()->add("entitlements.{$index}.value", "\"{$key}\" expects a {$valueType} value.");
            return;
        }

        if (in_array($valueType, [EntitlementValueType::INTEGER, EntitlementValueType::DECIMAL], true) && $value < 0) {
            $validator->errors()->add("entitlements.{$index}.value", "\"{$key}\" must not be negative.");
        }
    }
}
