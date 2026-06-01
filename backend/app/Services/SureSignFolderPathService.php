<?php

namespace App\Services;

/**
 * Centralised, security-hardened path-building helpers for the SureSign
 * local document mirror feature.
 *
 * Responsibilities
 * ─────────────────
 *  • Map module_key / document type values → physical folder names
 *  • Sanitize company / project / file names for safe filesystem use
 *  • Enumerate all folders that must exist in every mirrored project tree
 *
 * IMPORTANT: Never pass raw user-supplied strings as folder/file names
 * without running them through sanitizeSegment() first.
 */
class SureSignFolderPathService
{
    // ── Maps ─────────────────────────────────────────────────────────────────

    /**
     * module_key → physical folder name (used inside the project tree).
     * Used consistently by the mirror service, explorer APIs, and upload controllers.
     */
    public const MODULE_FOLDER_MAP = [
        'contracts'            => '01_Contracts',
        'commercial'           => '02_Commercial',
        'payment_applications' => '03_Payment Applications',
        'variations'           => '04_Variations',
        'notices'              => '05_Notices',
        'rfis'                 => '06_RFIs',
        'meetings'             => '07_Meetings',
        'qa_reports'           => '08_QA Reports',
        'snagging'             => '09_Snagging',
        'closeout'             => '10_Closeout',
        'adjudication'         => '11_Adjudication',
        'site_reports'         => '12_Site Reports',
        'ai_generated'         => '13_AI Generated',
        'general'              => '99_General Documents',
        // document-type slugs produced by DocumentGenerationService
        'payment_app'          => '03_Payment Applications',
        'variation'            => '04_Variations',
        'notice'               => '05_Notices',
        'site_report'          => '12_Site Reports',
    ];

    /**
     * contract_document_type → sub-folder name under 01_Contracts.
     */
    public const CONTRACT_SUBFOLDER_MAP = [
        'main_contract'        => 'Main Contract',
        'subcontract'          => 'Subcontracts',
        'consultant_agreement' => 'Consultant Agreements',
        'supplier_agreement'   => 'Supplier Agreements',
        'other'                => 'Other Contract Documents',
    ];

    // ── Lookup helpers ────────────────────────────────────────────────────────

    /**
     * Resolve module_key (e.g. 'contracts') or document-type slug
     * (e.g. 'payment_app') to the physical folder name.
     */
    public static function moduleKeyToFolderName(string $key): string
    {
        return self::MODULE_FOLDER_MAP[$key] ?? '99_General Documents';
    }

    /**
     * Resolve contract_document_type to its sub-folder name.
     */
    public static function contractSubFolderName(string $type): string
    {
        return self::CONTRACT_SUBFOLDER_MAP[$type] ?? 'Other Contract Documents';
    }

    // ── Folder lists ──────────────────────────────────────────────────────────

    /**
     * All physical folders to create/ensure when mirroring a project.
     * Paths use forward-slashes; convert to DIRECTORY_SEPARATOR in the service.
     */
    public static function allMirrorFolders(): array
    {
        return [
            '01_Contracts/Main Contract',
            '01_Contracts/Subcontracts',
            '01_Contracts/Consultant Agreements',
            '01_Contracts/Supplier Agreements',
            '01_Contracts/Other Contract Documents',
            '02_Commercial',
            '03_Payment Applications',
            '04_Variations',
            '05_Notices',
            '06_RFIs',
            '07_Meetings',
            '08_QA Reports',
            '09_Snagging',
            '10_Closeout',
            '11_Adjudication',
            '12_Site Reports',
            '13_AI Generated',
            '99_General Documents',
        ];
    }

    // ── Sanitization ──────────────────────────────────────────────────────────

    /**
     * Sanitize a single path segment (company name, project name, file name)
     * so it is safe to use as a folder or file name on Windows, macOS, and Linux.
     *
     * Rules applied:
     *  1. Reject/strip path-traversal sequences ( .. ./ )
     *  2. Remove characters illegal on at least one major OS: / \ : * ? " < > |
     *  3. Collapse runs of whitespace to a single space
     *  4. Trim leading/trailing whitespace and dots
     *  5. Limit to 100 characters
     *  6. Fall back to "Untitled" if the result is empty
     *
     * @param  string $value  Raw user-supplied or DB string
     * @return string         Safe filesystem segment
     */
    public static function sanitizeSegment(string $value): string
    {
        // Strip path-traversal attempts
        $value = str_replace(['..', './', '.\\'], '', $value);

        // Remove characters forbidden on Windows or with special FS meaning
        $value = preg_replace('/[\/\\\\:*?"<>|]/', '', $value ?? '');

        // Collapse whitespace
        $value = preg_replace('/\s+/', ' ', $value);

        // Trim dots and spaces (Windows disallows trailing dots/spaces in dir names)
        $value = trim($value, " \t\n\r\0\x0B.");

        // Enforce max length
        $value = mb_substr($value, 0, 100);
        $value = trim($value, " .");

        return $value ?: 'Untitled';
    }

    /**
     * Build the project-level display name used as the mirror folder for a project.
     * Format: "{Project Name} {Project Code}" or just "{Project Name}".
     *
     * @param  string      $projectName
     * @param  string|null $projectCode
     * @return string
     */
    public static function projectFolderName(string $projectName, ?string $projectCode = null): string
    {
        $raw = $projectName . ($projectCode ? " {$projectCode}" : '');
        return self::sanitizeSegment($raw);
    }
}
