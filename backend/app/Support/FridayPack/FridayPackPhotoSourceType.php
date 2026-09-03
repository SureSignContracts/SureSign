<?php

namespace App\Support\FridayPack;

use App\Models\SiteDiary;
use App\Models\ToolboxTalk;
use InvalidArgumentException;

/**
 * Automated Friday Pack, R1B — the ONE place a `FileUpload::attachable_type`
 * (a raw PHP FQCN, e.g. `App\Models\SiteDiary`) is translated into a
 * stable, customer-facing SureSign value. Neither
 * `FridayPackPhotoSelection.source_type` nor any API response is ever
 * allowed to carry a raw FQCN — this class is the sole translation
 * boundary, in both directions.
 *
 * V1 approved values only: `site_report`, `toolbox_talk`. An
 * unrecognised attachable class is never silently accepted as a photo
 * source — see `forAttachableClass()`.
 */
final class FridayPackPhotoSourceType
{
    public const SITE_REPORT = 'site_report';
    public const TOOLBOX_TALK = 'toolbox_talk';

    public const ALL = [
        self::SITE_REPORT,
        self::TOOLBOX_TALK,
    ];

    /** @var array<class-string, string> */
    private const MAP = [
        SiteDiary::class   => self::SITE_REPORT,
        ToolboxTalk::class => self::TOOLBOX_TALK,
    ];

    /**
     * @throws InvalidArgumentException when $attachableClass is not an
     *   approved Friday Pack photo source — never silently mapped to a
     *   best-guess value.
     */
    public static function forAttachableClass(string $attachableClass): string
    {
        return self::MAP[$attachableClass]
            ?? throw new InvalidArgumentException("\"{$attachableClass}\" is not an approved Friday Pack photo evidence source.");
    }

    /** @return class-string */
    public static function attachableClassFor(string $sourceType): string
    {
        $flipped = array_flip(self::MAP);

        return $flipped[$sourceType]
            ?? throw new InvalidArgumentException("\"{$sourceType}\" is not a recognised Friday Pack photo source type.");
    }

    public static function label(string $sourceType): string
    {
        return match ($sourceType) {
            self::SITE_REPORT  => 'Site Report',
            self::TOOLBOX_TALK => 'Toolbox Talk',
            default            => ucfirst(str_replace('_', ' ', $sourceType)),
        };
    }
}
