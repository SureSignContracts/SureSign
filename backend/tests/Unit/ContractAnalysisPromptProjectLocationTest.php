<?php

namespace Tests\Unit;

use App\Services\AI\ContractAnalysisPrompt;
use Tests\TestCase;

/**
 * Contract-Assisted Project Location & Automatic Map Pin, Part 1 & 28 —
 * covers the ContractAnalysisPrompt schema/instruction additions only.
 * There is no parsing helper to unit test here (unlike extractSummary()) —
 * project_location is read straight out of confirmed_data_json by
 * ProjectContractSetupSyncService (see ProjectContractSuggestionsTest's own
 * "Project Location" section for that behavioural coverage). What's tested
 * here is the prompt content itself: the schema shape the model is asked to
 * return, and the explicit instructions that keep it distinct from every
 * party's own address. No AI call is made anywhere in this test — the
 * prompt is a static string.
 */
class ContractAnalysisPromptProjectLocationTest extends TestCase
{
    public function test_schema_includes_a_nullable_project_location_object_under_contract_overview(): void
    {
        $schema = ContractAnalysisPrompt::user('irrelevant contract text');

        $this->assertStringContainsString('"project_location"', $schema);
        $this->assertStringContainsString('"address_line": null', $schema);
        $this->assertStringContainsString('"city": null', $schema);
        $this->assertStringContainsString('"region": null', $schema);
        $this->assertStringContainsString('"postal_code": null', $schema);
        // "country" also appears in contract_overview's employer/party
        // blocks in principle — assert it specifically inside the
        // project_location object's own key ordering instead of a bare
        // substring match.
        $this->assertMatchesRegularExpression(
            '/"project_location":\s*\{[^}]*"country":\s*null[^}]*\}/s',
            $schema
        );
    }

    public function test_system_instructions_define_project_location_as_the_site_not_a_party_address(): void
    {
        $instructions = ContractAnalysisPrompt::system();

        $this->assertStringContainsString('contract_overview.project_location', $instructions);
        $this->assertStringContainsString('PROJECT/SITE location', $instructions);
        // Explicitly names every party type it must never be confused with.
        foreach (['Employer', 'Contractor', 'Subcontractor', 'Architect', 'Quantity Surveyor', 'Project Manager'] as $partyType) {
            $this->assertStringContainsString($partyType, $instructions);
        }
    }

    public function test_system_instructions_forbid_inventing_or_returning_coordinates(): void
    {
        $instructions = ContractAnalysisPrompt::system();

        $this->assertStringContainsString('never return coordinates', $instructions);
        // The word "latitude"/"longitude" must never appear anywhere in the
        // schema itself — the model is never given a field to fill in.
        $schema = ContractAnalysisPrompt::user('irrelevant contract text');
        $this->assertStringNotContainsStringIgnoringCase('latitude', $schema);
        $this->assertStringNotContainsStringIgnoringCase('longitude', $schema);
    }

    public function test_system_instructions_require_partial_addresses_over_invented_components(): void
    {
        $instructions = ContractAnalysisPrompt::system();

        $this->assertStringContainsString('never construct a full address from partial information', $instructions);
        $this->assertStringContainsString('North London', $instructions); // the vague-location example from the spec
    }
}
