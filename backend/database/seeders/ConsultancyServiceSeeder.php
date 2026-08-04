<?php

namespace Database\Seeders;

use App\Models\ConsultancyService;
use App\Services\Consultancy\ConsultancyCatalogueService;
use Illuminate\Database\Seeder;

/**
 * Seeds the three default Consultancy Services (Phase C1). These are
 * default configuration values, not hardcoded business rules — editable
 * afterwards like any consultancy_services row. Uses
 * ConsultancyCatalogueService so the linked AppointmentType is always
 * created in the same call, never a second, divergent creation path.
 */
class ConsultancyServiceSeeder extends Seeder
{
    public function run(): void
    {
        $catalogueService = app(ConsultancyCatalogueService::class);

        $services = [
            [
                'code'                             => 'quick-consultation',
                'display_name'                     => 'Quick Consultation',
                'description'                      => 'A short introductory consultation — platform familiarisation and determining whether a longer consultation is appropriate. Not a substitute for a paid consultation: no detailed document review, no written report, no project-specific legal or commercial advice.',
                'public_description'               => 'A low-friction 15-minute introduction to SureSign Consultancy — say hello, get orientated, and find out if a fuller consultation would help.',
                'enabled'                          => true,
                'publicly_bookable'                => true,
                'available_to_existing_customers'  => true,
                'price_minor_units'                => 100,
                'currency'                         => 'GBP',
                'display_order'                    => 1,
                'is_introductory'                  => true,
                'duration_minutes'                 => 15,
                'requires_confirmation'            => false,
                'meeting_method'                   => 'tbc',
            ],
            [
                'code'                             => 'standard-consultation',
                'display_name'                     => 'Standard Consultation',
                'description'                      => 'A standard 30-minute professional consultation covering a specific contract administration query or issue.',
                'public_description'               => 'Discuss a payment application, notice, variation, or general contract administration question with an experienced professional.',
                'enabled'                          => true,
                'publicly_bookable'                => true,
                'available_to_existing_customers'  => true,
                'price_minor_units'                => 4000,
                'currency'                         => 'GBP',
                'display_order'                    => 2,
                'is_introductory'                  => false,
                'duration_minutes'                 => 30,
                'requires_confirmation'            => false,
                'meeting_method'                   => 'tbc',
            ],
            [
                'code'                             => 'extended-consultation',
                'display_name'                     => 'Extended Consultation',
                'description'                      => 'An extended 60-minute consultation for more involved commercial or contractual matters requiring a fuller discussion.',
                'public_description'               => 'A full hour with an experienced construction professional for a more detailed commercial or contractual discussion.',
                'enabled'                          => true,
                'publicly_bookable'                => true,
                'available_to_existing_customers'  => true,
                'price_minor_units'                => 7500,
                'currency'                         => 'GBP',
                'display_order'                    => 3,
                'is_introductory'                  => false,
                'duration_minutes'                 => 60,
                'requires_confirmation'            => false,
                'meeting_method'                   => 'tbc',
            ],
        ];

        foreach ($services as $data) {
            if (ConsultancyService::where('code', $data['code'])->exists()) {
                continue;
            }
            $catalogueService->create($data);
        }
    }
}
