<?php

namespace App\Support\Consultancy;

use App\Models\SuresignSetting;

/**
 * The two supported recipient modes for the Consultancy "new booking"
 * in-app notification (`NotificationService::CONSULTATION_BOOKED`), stored
 * in `suresign_settings.consultancy_new_booking_notification_recipients`.
 * Configurable per explicit instruction, defaulting to ALL_ADMINS —
 * mirrors `App\Support\AI\AiCreditOperatingMode`'s own shape (a small,
 * dedicated constants class plus a fail-safe `current()` reader) rather
 * than a raw string compared inline at the one call site.
 *
 * `current()` is the ONLY place this setting is read from anywhere in the
 * codebase — an unrecognised/corrupt stored value fails safe to
 * ALL_ADMINS (the more visible option — a missed notification is worse
 * than an extra one), never silently to ASSIGNED_CONSULTANT.
 */
class ConsultancyNewBookingNotificationRecipients
{
    public const ALL_ADMINS = 'all_admins';
    public const ASSIGNED_CONSULTANT = 'assigned_consultant';

    public const ALL = [self::ALL_ADMINS, self::ASSIGNED_CONSULTANT];

    public static function current(): string
    {
        $value = SuresignSetting::instance()->consultancy_new_booking_notification_recipients;

        return in_array($value, self::ALL, true) ? $value : self::ALL_ADMINS;
    }
}
