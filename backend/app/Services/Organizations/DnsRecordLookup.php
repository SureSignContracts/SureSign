<?php

namespace App\Services\Organizations;

/**
 * Thin wrapper around PHP's `dns_get_record()` — exists solely so
 * `DomainVerificationService` can be tested without making real network DNS
 * calls (bind a fake in the container in tests) while production code path
 * still performs a genuine lookup by default.
 */
class DnsRecordLookup
{
    /** @return array<int, array<string, mixed>> */
    public function txt(string $host): array
    {
        return @dns_get_record($host, DNS_TXT) ?: [];
    }

    /** @return array<int, array<string, mixed>> */
    public function cname(string $host): array
    {
        return @dns_get_record($host, DNS_CNAME) ?: [];
    }
}
