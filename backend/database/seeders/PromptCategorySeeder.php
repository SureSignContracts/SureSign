<?php

namespace Database\Seeders;

use App\Models\PromptCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PromptCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Contracts',             'icon' => 'FileText',      'sort_order' => 1],
            ['name' => 'Commercial',             'icon' => 'DollarSign',    'sort_order' => 2],
            ['name' => 'Payment Applications',   'icon' => 'Receipt',       'sort_order' => 3],
            ['name' => 'Variations',             'icon' => 'GitBranch',     'sort_order' => 4],
            ['name' => 'Notices',                'icon' => 'Bell',          'sort_order' => 5],
            ['name' => 'RFIs',                   'icon' => 'HelpCircle',    'sort_order' => 6],
            ['name' => 'Meetings',               'icon' => 'Users',         'sort_order' => 7],
            ['name' => 'Site Reports',           'icon' => 'ClipboardList', 'sort_order' => 8],
            ['name' => 'QA Reports',             'icon' => 'CheckSquare',   'sort_order' => 9],
            ['name' => 'Snagging',               'icon' => 'AlertCircle',   'sort_order' => 10],
            ['name' => 'Closeout',               'icon' => 'Archive',       'sort_order' => 11],
            ['name' => 'Adjudication',           'icon' => 'Scale',         'sort_order' => 12],
            ['name' => 'Documents',              'icon' => 'FolderOpen',    'sort_order' => 13],
            ['name' => 'General Admin',          'icon' => 'Briefcase',     'sort_order' => 14],
        ];

        foreach ($categories as $data) {
            PromptCategory::updateOrCreate(
                ['slug' => Str::slug($data['name'])],
                array_merge($data, [
                    'slug'      => Str::slug($data['name']),
                    'is_active' => true,
                ])
            );
        }
    }
}
