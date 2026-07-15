<?php

namespace Tests\Unit;

use App\Services\TradePackages\WorkspaceNavigationResolver;
use PHPUnit\Framework\TestCase;

/**
 * Final cleanup (Task 3): contract_ai_analysis previously had no TAB_MAP
 * entry at all, so WorkspaceNavigationResolver::actionUrl() returned null
 * for it unconditionally — Contract AI notifications had nowhere to
 * navigate to even though the Contracts page (where the AI review UI lives)
 * already existed. Trade package AI analysis already had one and is
 * unaffected by this change.
 */
class WorkspaceNavigationResolverContractAiTest extends TestCase
{
    public function test_contract_ai_analysis_resolves_to_the_contracts_page(): void
    {
        $url = WorkspaceNavigationResolver::actionUrl(42, 'contract_ai_analysis', 7);

        $this->assertSame('/app/projects/42/contracts', $url);
    }

    public function test_contract_ai_analysis_is_never_trade_package_scoped_in_practice(): void
    {
        // Defensive: even if a trade_package_id were somehow passed, it must
        // not silently route to a subcontract workspace tab, since a
        // contract-level analysis never actually belongs to one.
        $url = WorkspaceNavigationResolver::actionUrl(42, 'contract_ai_analysis', 7, null);

        $this->assertSame('/app/projects/42/contracts', $url);
    }
}
