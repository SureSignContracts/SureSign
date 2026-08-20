<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Global Address Data Cleanup — regression coverage for
 * `2026_08_20_000002_fix_organizations_country_default`, mirroring the
 * same class of fix already covered informally for `projects.country`
 * (see `ProjectContractSuggestionsTest`'s "'AU' preserved, never
 * normalized" case). This is the first DEDICATED schema-default
 * regression test for either column — deliberately a small, focused file,
 * not a broad Organisation test suite.
 */
class OrganizationCountryDefaultTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrgAndUser(array $orgOverrides = []): array
    {
        static $n = 0;
        $n++;

        $org = Organization::create(array_merge([
            'name' => "Org {$n}",
            'slug' => "org-{$n}",
            'timezone' => 'Europe/London',
        ], $orgOverrides));
        $user = User::factory()->create(['organization_id' => $org->id]);

        return [$org, $user];
    }

    /**
     * A static scan of the migration file itself, not a live
     * `information_schema` query — this test suite runs on SQLite
     * (phpunit.xml forces `DB_CONNECTION=sqlite`), which has no
     * `information_schema`, so a live schema query here would test
     * nothing real about MySQL production behaviour anyway. Mirrors
     * `ForeignKeyMigrationOrderTest`'s own established convention for
     * exactly this situation: verify MySQL-relevant schema intent by
     * reading the migration file directly. The real live-database
     * behaviour (NOT NULL DEFAULT 'AU' → nullable, no default, zero row
     * mutation) was independently verified against the actual dev MySQL
     * database as part of this change — see project-context.md.
     */
    public function test_migration_file_removes_the_au_default_and_allows_null(): void
    {
        $path = base_path('database/migrations/2026_08_20_000002_fix_organizations_country_default.php');
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        [$upBody, $downBody] = $this->splitUpAndDown($contents);

        // up(): the forward migration must remove the AU default and allow null.
        $this->assertStringContainsString("nullable()->default(null)->change()", $upBody);

        // down(): the rollback restores the EXACT prior contract (NOT NULL,
        // default 'AU') — a real, deliberate restoration, not accidentally
        // present via some unrelated reference.
        $this->assertStringContainsString("nullable(false)->default('AU')->change()", $downBody);
    }

    /** @return array{0: string, 1: string} */
    private function splitUpAndDown(string $migrationSource): array
    {
        $upStart = strpos($migrationSource, 'function up()');
        $downStart = strpos($migrationSource, 'function down()');
        $this->assertNotFalse($upStart);
        $this->assertNotFalse($downStart);

        return [
            substr($migrationSource, $upStart, $downStart - $upStart),
            substr($migrationSource, $downStart),
        ];
    }

    public function test_organisation_can_be_created_with_null_country(): void
    {
        [$org] = $this->makeOrgAndUser(['country' => null]);

        $this->assertNull($org->fresh()->country);
    }

    public function test_omitting_country_at_creation_does_not_default_to_au(): void
    {
        // Deliberately does NOT pass 'country' at all — this is the exact
        // shape that used to silently inherit the database default.
        [$org] = $this->makeOrgAndUser();

        $this->assertNull($org->fresh()->country);
        $this->assertNotSame('AU', $org->fresh()->country);
    }

    public function test_explicit_australia_is_stored_as_the_full_name_not_au(): void
    {
        [$org] = $this->makeOrgAndUser(['country' => 'Australia']);

        $this->assertSame('Australia', $org->fresh()->country);
    }

    public function test_explicit_philippines_is_preserved(): void
    {
        [$org] = $this->makeOrgAndUser(['country' => 'Philippines']);

        $this->assertSame('Philippines', $org->fresh()->country);
    }

    /**
     * An existing 'AU' value (whatever its real provenance — a genuine
     * Australian organisation or a historical default artifact) must
     * never be silently rewritten by anything in this codebase. This test
     * does not "fix" the row — it asserts the opposite: it stays exactly
     * 'AU' through an ordinary read/update cycle on an unrelated field.
     */
    public function test_existing_au_value_is_never_normalized_or_rewritten(): void
    {
        [$org] = $this->makeOrgAndUser(['country' => 'AU']);

        $org->update(['phone' => '+61 2 5550 1234']);

        $this->assertSame('AU', $org->fresh()->country);
    }

    /**
     * A user with NO existing organisation — this is what makes
     * `onboardCompany()` take its `Organization::create()` branch (a
     * user who already has an org instead hits the `update()` branch,
     * which correctly leaves an omitted field untouched — that's normal
     * partial-update behaviour, not the defect this migration fixes). The
     * `create()` branch is the one where an omitted `country` used to
     * silently inherit the database default.
     */
    private function makeUserWithNoOrganisation(): User
    {
        return User::factory()->create(['organization_id' => null]);
    }

    public function test_onboarding_company_creation_with_no_country_results_in_null(): void
    {
        $user = $this->makeUserWithNoOrganisation();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/organization/onboard/company', [
            'name' => 'Acme Construction Ltd',
            // 'country' deliberately omitted.
        ]);

        $response->assertOk();
        $this->assertNull($user->fresh()->organization->country);
    }

    public function test_onboarding_company_creation_with_explicit_country_is_preserved_verbatim(): void
    {
        $user = $this->makeUserWithNoOrganisation();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/organization/onboard/company', [
            'name' => 'Acme Construction Ltd',
            'country' => 'Philippines',
        ]);

        $response->assertOk();
        $this->assertSame('Philippines', $user->fresh()->organization->country);
    }

    /**
     * Loads the actual migration file and returns a live instance of its
     * anonymous Migration class — these rollback-safety tests call the
     * REAL `up()`/`down()` methods against this test's own isolated
     * SQLite database (via `RefreshDatabase`), not a re-description of
     * their logic. Each test method gets a freshly migrated schema before
     * it runs (standard `RefreshDatabase` behaviour), so calling `down()`/
     * `up()` here only ever affects this one test's own throwaway
     * database — never the real dev database, and never another test.
     */
    private function migration(): object
    {
        return require base_path('database/migrations/2026_08_20_000002_fix_organizations_country_default.php');
    }

    public function test_rollback_succeeds_when_no_null_country_rows_exist(): void
    {
        // The freshly migrated test database starts with zero organisation
        // rows at all, so this is the genuinely safe case by construction.
        $this->migration()->down();

        // The restored NOT NULL constraint must be real, not just
        // unexercised — SQLite enforces NOT NULL natively (unlike foreign
        // keys). An EXPLICIT null (as opposed to simply omitting the
        // column, which the restored DEFAULT 'AU' would silently absorb —
        // that's the old behaviour, not a violation) must now fail.
        $this->expectException(\Illuminate\Database\QueryException::class);
        \Illuminate\Support\Facades\DB::table('organizations')->insert([
            'name' => 'Should Fail', 'slug' => 'should-fail', 'timezone' => 'Europe/London', 'country' => null,
        ]);
    }

    public function test_rollback_throws_when_a_null_country_row_exists(): void
    {
        $this->makeOrgAndUser(['country' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/NULL country/');
        $this->migration()->down();
    }

    /**
     * The most important guarantee: even though the rollback attempt
     * fails, it must fail WITHOUT having rewritten the NULL row to 'AU'
     * (or anything else) first. This test would only pass if the
     * exception is thrown before any mutating statement runs.
     */
    public function test_rollback_never_rewrites_null_to_au_even_on_failure(): void
    {
        [$org] = $this->makeOrgAndUser(['country' => null]);

        try {
            $this->migration()->down();
            $this->fail('Expected rollback to throw when a NULL country row exists.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertNull($org->fresh()->country);
    }
}
