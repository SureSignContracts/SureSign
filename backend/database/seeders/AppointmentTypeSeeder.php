<?php

namespace Database\Seeders;

use App\Models\AppointmentType;
use Illuminate\Database\Seeder;

class AppointmentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'Book a Demo',              'slug' => 'demo',                'duration_minutes' => 30, 'is_public' => true,  'requires_confirmation' => false, 'display_order' => 1],
            ['name' => 'Product Walkthrough',      'slug' => 'product-walkthrough', 'duration_minutes' => 30, 'is_public' => true,  'requires_confirmation' => false, 'display_order' => 2],
            ['name' => 'Customer Onboarding',       'slug' => 'customer-onboarding', 'duration_minutes' => 60, 'is_public' => false, 'requires_confirmation' => true,  'display_order' => 3],
            ['name' => 'Training Session',          'slug' => 'training-session',    'duration_minutes' => 45, 'is_public' => false, 'requires_confirmation' => true,  'display_order' => 4],
            ['name' => 'Support Consultation',      'slug' => 'support-consultation','duration_minutes' => 30, 'is_public' => false, 'requires_confirmation' => false, 'display_order' => 5],
            ['name' => 'Account Review',            'slug' => 'account-review',      'duration_minutes' => 30, 'is_public' => false, 'requires_confirmation' => false, 'display_order' => 6],
            ['name' => 'General Enquiry',           'slug' => 'general-enquiry',      'duration_minutes' => 15, 'is_public' => true,  'requires_confirmation' => true,  'display_order' => 7],
        ];

        foreach ($types as $data) {
            AppointmentType::firstOrCreate(
                ['slug' => $data['slug']],
                array_merge($data, [
                    'is_active'        => true,
                    'assignment_mode'  => 'manual',
                    'meeting_method'   => 'tbc',
                ])
            );
        }
    }
}
