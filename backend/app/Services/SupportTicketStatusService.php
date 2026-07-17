<?php

namespace App\Services;

/**
 * Centralized support-ticket status workflow — the only place that decides
 * which status a ticket may move to, so controllers never contain their own
 * transition conditionals.
 *
 * 'open' is kept as a valid, storable value (for tickets created before this
 * workflow existed, and as the column's schema default) but is never
 * assigned by any code path going forward — SupportTicketController::store()
 * creates new tickets directly in WAITING_FOR_SUPPORT, per the product
 * decision that a freshly submitted ticket is immediately "waiting on us,"
 * not sitting in an extra unstaffed "open" limbo state. Anywhere 'open' is
 * still encountered (legacy rows), it's treated identically to
 * WAITING_FOR_SUPPORT by every method below.
 *
 * Reopen policy (the one the product chose, out of the two options the
 * batch brief allowed): a client reply to a RESOLVED ticket reopens it
 * automatically back to WAITING_FOR_SUPPORT — no separate "Reopen" action
 * for the client. A CLOSED ticket is more final: clients cannot reply to it
 * at all (canClientReply() returns false), so it can only be reopened by an
 * operator via the explicit status-change endpoint.
 */
class SupportTicketStatusService
{
    public const OPEN = 'open';
    public const WAITING_FOR_SUPPORT = 'waiting_for_support';
    public const WAITING_FOR_YOU = 'waiting_for_you';
    public const RESOLVED = 'resolved';
    public const CLOSED = 'closed';

    public const ALL = [self::OPEN, self::WAITING_FOR_SUPPORT, self::WAITING_FOR_YOU, self::RESOLVED, self::CLOSED];

    /** Status an operator may explicitly move a ticket to, from its current status, via the status-change endpoint. */
    private const OPERATOR_TRANSITIONS = [
        self::OPEN                => [self::WAITING_FOR_SUPPORT, self::WAITING_FOR_YOU, self::RESOLVED, self::CLOSED],
        self::WAITING_FOR_SUPPORT => [self::WAITING_FOR_YOU, self::RESOLVED, self::CLOSED],
        self::WAITING_FOR_YOU     => [self::WAITING_FOR_SUPPORT, self::RESOLVED, self::CLOSED],
        self::RESOLVED             => [self::WAITING_FOR_SUPPORT, self::CLOSED],
        self::CLOSED               => [self::WAITING_FOR_SUPPORT], // explicit operator reopen
    ];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function canOperatorTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return true; // idempotent re-save — caller decides whether to skip side effects
        }

        return in_array($to, self::OPERATOR_TRANSITIONS[$from] ?? [], true);
    }

    /** A CLOSED ticket cannot receive a client reply — every other status can. */
    public static function canClientReply(string $status): bool
    {
        return $status !== self::CLOSED;
    }

    /** Status to move a ticket to after a support (operator) reply, or null to leave it unchanged. */
    public static function afterSupportReply(string $current): ?string
    {
        return in_array($current, [self::OPEN, self::WAITING_FOR_SUPPORT], true) ? self::WAITING_FOR_YOU : null;
    }

    /** Status to move a ticket to after a client reply — always back to "waiting on us," including the RESOLVED-reopen case. */
    public static function afterClientReply(): string
    {
        return self::WAITING_FOR_SUPPORT;
    }
}
