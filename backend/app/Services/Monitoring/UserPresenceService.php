<?php

namespace App\Services\Monitoring;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Lightweight authenticated-user presence, backed entirely by Redis — no
 * database writes, no process-local state, safe across multiple backend
 * replicas.
 *
 * Structure (see docs/internal super-admin monitoring doc for the full
 * write-up of the "online" definition and its limitations):
 *
 *   - `monitoring:presence:index` (sorted set) — member: user id,
 *     score: unix timestamp of last recorded activity. This is the only
 *     structure ever scanned to answer "who is online" — no KEYS/SCAN over
 *     per-user keys, which would be expensive at scale.
 *   - `monitoring:presence:data` (hash) — field: user id, value: JSON
 *     payload {user_id, name, email, role, organization_id,
 *     organization_name, module_key, last_active_at}. Refreshed alongside
 *     the sorted-set entry; entries for users pruned from the sorted set
 *     are deleted from this hash in the same pass.
 *   - `monitoring:presence:throttle:{user_id}` (string, TTL 60s) — write
 *     throttle so a single user's traffic updates presence at most once
 *     per minute regardless of request volume.
 *
 * A user counts as online if their sorted-set score is within the last
 * ONLINE_WINDOW_SECONDS (5 minutes). That window — not a Redis TTL on the
 * index entry itself — is what "expires" presence; entries older than the
 * window are pruned lazily on the next read.
 */
class UserPresenceService
{
    private const INDEX_KEY       = 'monitoring:presence:index';
    private const DATA_KEY        = 'monitoring:presence:data';
    private const THROTTLE_PREFIX = 'monitoring:presence:throttle:';

    public const ONLINE_WINDOW_SECONDS    = 300;  // 5 minutes — the "online" definition
    public const REFRESH_THROTTLE_SECONDS = 60;   // at most once/minute per user
    public const RETENTION_SECONDS        = 1200; // 20 minutes — how long entries are kept
                                                   // before being pruned, wide enough to also
                                                   // answer "activity in the last 15 minutes"
                                                   // from the same sorted set.

    /**
     * Record that a user performed meaningful authenticated activity.
     * Throttled to at most one Redis write per user per
     * REFRESH_THROTTLE_SECONDS. Never throws — any Redis failure is logged
     * and swallowed so a monitoring outage never affects the request.
     */
    public function recordActivity(User $user, ?string $moduleKey): void
    {
        try {
            $throttleKey = self::THROTTLE_PREFIX . $user->id;

            // SET ... NX EX — atomic "claim this window" check. If another
            // request (or replica) already refreshed this user's presence
            // in the last minute, this returns false and we skip the write.
            $claimed = Redis::set($throttleKey, '1', 'EX', self::REFRESH_THROTTLE_SECONDS, 'NX');

            if (! $claimed) {
                return;
            }

            $now = time();
            $payload = json_encode([
                'user_id'           => $user->id,
                'name'              => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->name,
                'email'             => $user->email,
                'role'              => $user->getRoleNames()->first(),
                'organization_id'   => $user->organization_id,
                'organization_name' => $user->organization?->name,
                'module_key'        => $moduleKey,
                'last_active_at'    => $now,
            ]);

            Redis::zadd(self::INDEX_KEY, $now, (string) $user->id);
            Redis::hset(self::DATA_KEY, (string) $user->id, $payload);
        } catch (Throwable $e) {
            Log::warning('UserPresenceService: failed to record presence', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Whether Redis is currently reachable for presence tracking. When
     * false, callers must present presence as "unavailable", never as
     * "zero users online" — those are different facts.
     */
    public function isAvailable(): bool
    {
        try {
            Redis::ping();
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Currently online users (active within ONLINE_WINDOW_SECONDS), most
     * recently active first. Returns null when Redis is unavailable.
     *
     * @return array<int, array{user_id:int,name:?string,email:?string,role:?string,organization_id:?int,organization_name:?string,module_key:?string,last_active_at:int}>|null
     */
    public function getOnlineUsers(): ?array
    {
        try {
            $this->pruneStaleEntries();

            $cutoff = time() - self::ONLINE_WINDOW_SECONDS;
            $onlineIds = Redis::zrangebyscore(self::INDEX_KEY, $cutoff, '+inf');
            if (empty($onlineIds)) {
                return [];
            }

            $payloads = Redis::hmget(self::DATA_KEY, $onlineIds);

            $users = [];
            foreach ($payloads as $payload) {
                if (! $payload) {
                    continue;
                }
                $decoded = json_decode($payload, true);
                if (is_array($decoded)) {
                    $users[] = $decoded;
                }
            }

            usort($users, fn ($a, $b) => ($b['last_active_at'] ?? 0) <=> ($a['last_active_at'] ?? 0));

            return $users;
        } catch (Throwable $e) {
            Log::warning('UserPresenceService: failed to read presence', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Count of currently-online users. Null when presence is unavailable.
     */
    public function getOnlineCount(): ?int
    {
        $users = $this->getOnlineUsers();
        return $users === null ? null : count($users);
    }

    /**
     * Count of distinct organizations with at least one online user. Null
     * when presence is unavailable.
     */
    public function getActiveOrganizationsCount(): ?int
    {
        $users = $this->getOnlineUsers();
        if ($users === null) {
            return null;
        }

        return collect($users)->pluck('organization_id')->filter()->unique()->count();
    }

    /**
     * Distinct users with recorded activity within the given window
     * (seconds), e.g. 900 for "last 15 minutes". Bounded by
     * RETENTION_SECONDS — a wider window would silently under-count since
     * older entries are pruned. Null when presence is unavailable.
     */
    public function getRecentActivityCount(int $windowSeconds): ?int
    {
        try {
            $this->pruneStaleEntries();

            $cutoff = time() - min($windowSeconds, self::RETENTION_SECONDS);

            return (int) Redis::zcount(self::INDEX_KEY, $cutoff, '+inf');
        } catch (Throwable $e) {
            Log::warning('UserPresenceService: failed to count recent activity', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Removes entries older than RETENTION_SECONDS from both the sorted
     * set and the parallel data hash, so stale users never accumulate in
     * either structure. Called at the start of every read.
     */
    private function pruneStaleEntries(): void
    {
        $retentionCutoff = time() - self::RETENTION_SECONDS;

        $stale = Redis::zrangebyscore(self::INDEX_KEY, '-inf', '(' . $retentionCutoff);
        if (! empty($stale)) {
            Redis::zremrangebyscore(self::INDEX_KEY, '-inf', '(' . $retentionCutoff);
            Redis::hdel(self::DATA_KEY, ...$stale);
        }
    }
}
