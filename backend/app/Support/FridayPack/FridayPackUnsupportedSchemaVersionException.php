<?php

namespace App\Support\FridayPack;

use RuntimeException;

/**
 * Automated Friday Pack, R1A.1 — thrown by FridayPackPdfService::generate()
 * when a pack's own `snapshot_json['schema_version']` is not
 * FridayPackSchemaVersion::LEGACY_VERSION — i.e. a schema-2 (or otherwise
 * unrecognised-version) pack, for which the existing `pdfs.friday-pack`
 * Blade template/FridayPackPdfPresenter have not yet been updated to
 * render correctly. A distinct exception type (mirrors
 * FridayPackDeliveryLockedException's exact shape) so
 * FridayPackController::pdf() can map ONLY this specific, expected
 * "not yet supported" case to a controlled 409 — never a generic 500,
 * and never a silently malformed PDF produced by passing schema-2
 * content through the schema-1-only template.
 *
 * Temporary by design: removed once a schema-2-aware PDF template lands
 * in a later Friday Pack Realignment phase (see CLAUDE.md's Friday Pack
 * PDF redesign phase).
 */
class FridayPackUnsupportedSchemaVersionException extends RuntimeException
{
}
