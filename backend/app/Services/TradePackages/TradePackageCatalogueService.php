<?php

namespace App\Services\TradePackages;

class TradePackageCatalogueService
{
    /**
     * Single source of truth for the standard trade package catalogue.
     * Order in this array is the display order.
     *
     * Consumed by: GenerateTradePackageFoldersService (code resolution),
     * TradePackageCatalogueController (frontend), and available to any
     * future import/AI workflow that needs to resolve a package name to code.
     */
    private const PACKAGES = [
        ['name' => 'Concrete Frame',         'code' => 'CF'],
        ['name' => 'Brickwork',              'code' => 'BW'],
        ['name' => 'Windows & Doors',        'code' => 'WD'],
        ['name' => 'Roofing',                'code' => 'RF'],
        ['name' => 'M&E',                    'code' => 'ME'],
        ['name' => 'Groundworks',            'code' => 'GW'],
        ['name' => 'Drylining & Plastering', 'code' => 'DP'],
        ['name' => 'Steelwork',              'code' => 'ST'],
        ['name' => 'Landscaping',            'code' => 'LS'],
        ['name' => 'Demolition',             'code' => 'DM'],
        ['name' => 'Fire Stopping',          'code' => 'FS'],
        ['name' => 'External Works',         'code' => 'EW'],
        ['name' => 'Access Control',         'code' => 'AC'],
        ['name' => 'CCTV',                   'code' => 'CC'],
        ['name' => 'Solar PV',               'code' => 'SP'],
    ];

    /**
     * Returns the catalogue with display order, ready for API/frontend consumption.
     */
    public static function all(): array
    {
        return array_values(array_map(
            fn (array $pkg, int $order) => [
                'name'      => $pkg['name'],
                'code'      => $pkg['code'],
                'order'     => $order,
                'is_custom' => false,
            ],
            self::PACKAGES,
            array_keys(self::PACKAGES)
        ));
    }

    /**
     * Resolve the standard code for a known package name, if any.
     * Exact match only — mirrors the original STANDARD_CODE_MAP array-key lookup.
     */
    public static function codeForName(string $name): ?string
    {
        foreach (self::PACKAGES as $pkg) {
            if ($pkg['name'] === $name) {
                return $pkg['code'];
            }
        }
        return null;
    }
}
