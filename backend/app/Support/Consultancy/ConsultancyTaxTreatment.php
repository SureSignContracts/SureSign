<?php

namespace App\Support\Consultancy;

/**
 * Consultancy Live Booking Upgrade, Stage 3 — the tax-treatment value
 * recorded on every ConsultancyPayment snapshot. Deliberately never a
 * nullable/ambiguous state: every historical transaction states exactly
 * what policy was applied to it, even before any real tax policy exists.
 *
 * NOT_SEPARATELY_CALCULATED is Stage 3's only launch value — the
 * configured Consultancy price is treated as the final customer-visible
 * total, no separate tax line is calculated, automatic tax is disabled.
 * This is NOT a claim that the price is legally VAT-inclusive — proper
 * VAT/tax policy, invoicing requirements, registration status, and
 * place-of-supply rules are an explicit commercial/legal decision outside
 * this stage's scope. A future stage introducing real tax calculation adds
 * a NEW value here rather than reinterpreting this one.
 */
final class ConsultancyTaxTreatment
{
    public const NOT_SEPARATELY_CALCULATED = 'not_separately_calculated';

    public const ALL = [self::NOT_SEPARATELY_CALCULATED];
}
