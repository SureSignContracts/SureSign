<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Organization;
use App\Models\PaymentApplication;
use App\Models\Project;
use App\Models\User;
use App\Services\CurrencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Commercial Correctness Hardening — Final Currency Template Closeout
 * (2026-08-21).
 *
 * pdfs/payment-certificate.blade.php previously hardcoded '£' in two
 * customer-facing places (Contract Sum row, "Amount (£)" table header)
 * regardless of the project's real currency. Fixed to use the `$currency`
 * value DocumentGenerationService::generatePdf() already guarantees is
 * injected (via CurrencyService::resolveSymbol($project)) before this view
 * ever renders — no second CurrencyService call was added to the template
 * itself.
 *
 * Renders the Blade view directly (existing Laravel `view()` helper —
 * no new PDF test framework) to assert on the exact HTML string, which is
 * far more precise than parsing rendered PDF bytes.
 */
class PaymentCertificateCurrencyTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrgProjectContractApp(string $currency): array
    {
        static $n = 0;
        $n++;

        $org = Organization::create(['name' => "Org {$n}", 'slug' => "org-{$n}-" . uniqid(), 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = Project::create([
            'organization_id' => $org->id, 'created_by' => $user->id,
            'name' => "Project {$n}", 'status' => 'active', 'currency' => $currency,
        ]);
        $contract = Contract::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'title' => 'Main Contract', 'type' => 'main_contract', 'status' => 'active',
            'contract_sum' => 50000,
        ]);
        $paymentApplication = PaymentApplication::create([
            'project_id' => $project->id, 'contract_id' => $contract->id,
            'organization_id' => $org->id, 'created_by' => $user->id,
            'application_number' => $n, 'application_date' => now()->toDateString(),
            'gross_valuation' => 10000, 'less_retention' => 500, 'less_previous_payments' => 1000,
            'amount_due' => 8500, 'certified_amount' => 8000,
            'status' => 'certified', 'certified_at' => now(), 'certified_by' => $user->id,
        ]);

        return compact('org', 'user', 'project', 'contract', 'paymentApplication');
    }

    /**
     * Renders exactly the viewData DocumentGenerationService::generatePdf()
     * would build for this view — including the `currency` key it
     * auto-injects when a caller (as both real call sites do) doesn't
     * supply one.
     */
    private function renderCertificate(Project $project, PaymentApplication $paymentApplication, User $certifiedBy): string
    {
        $paymentApplication->load('contract', 'tradePackage');

        return view('pdfs.payment-certificate', [
            'paymentApplication' => $paymentApplication,
            'certifiedBy'        => $certifiedBy,
            'project'            => $project,
            'currency'           => CurrencyService::resolveSymbol($project),
        ])->render();
    }

    // ── Symbol resolution through the real project→currency path ─────────

    public function test_currency_symbol_resolves_correctly_for_gbp_usd_eur_aud(): void
    {
        $cases = ['GBP' => '£', 'USD' => '$', 'EUR' => '€', 'AUD' => 'A$'];

        foreach ($cases as $code => $symbol) {
            ['project' => $project] = $this->makeOrgProjectContractApp($code);
            $this->assertSame($symbol, CurrencyService::resolveSymbol($project), "Expected {$code} to resolve to {$symbol}");
        }
    }

    // ── Payment Certificate template: no hardcoded £ leaks for non-GBP ────

    public function test_gbp_project_certificate_shows_pound_symbol(): void
    {
        $data = $this->makeOrgProjectContractApp('GBP');
        $html = $this->renderCertificate($data['project'], $data['paymentApplication'], $data['user']);

        $this->assertStringContainsString('£50,000.00', $html, 'Contract Sum row should show £ for a GBP project');
        $this->assertStringContainsString('Amount (£)', $html);
    }

    public function test_usd_project_certificate_never_shows_pound_symbol(): void
    {
        $data = $this->makeOrgProjectContractApp('USD');
        $html = $this->renderCertificate($data['project'], $data['paymentApplication'], $data['user']);

        $this->assertStringNotContainsString('£', $html, 'A USD project certificate must never render a hardcoded £');
        $this->assertStringContainsString('$50,000.00', $html);
        $this->assertStringContainsString('Amount ($)', $html);
    }

    public function test_eur_project_certificate_never_shows_pound_symbol(): void
    {
        $data = $this->makeOrgProjectContractApp('EUR');
        $html = $this->renderCertificate($data['project'], $data['paymentApplication'], $data['user']);

        $this->assertStringNotContainsString('£', $html);
        $this->assertStringContainsString('€50,000.00', $html);
        $this->assertStringContainsString('Amount (€)', $html);
    }

    public function test_aud_project_certificate_never_shows_pound_symbol(): void
    {
        $data = $this->makeOrgProjectContractApp('AUD');
        $html = $this->renderCertificate($data['project'], $data['paymentApplication'], $data['user']);

        $this->assertStringNotContainsString('£', $html);
        $this->assertStringContainsString('A$50,000.00', $html);
        $this->assertStringContainsString('Amount (A$)', $html);
    }

    // ── Numeric values remain untouched by the presentation fix ──────────

    public function test_certificate_numeric_values_are_unchanged_by_currency_symbol_fix(): void
    {
        $data = $this->makeOrgProjectContractApp('AUD');
        $html = $this->renderCertificate($data['project'], $data['paymentApplication'], $data['user']);

        $this->assertStringContainsString('10,000.00', $html); // gross valuation
        $this->assertStringContainsString('(500.00)', $html);  // retention deduction
        $this->assertStringContainsString('8,500.00', $html);  // amount applied for
        $this->assertStringContainsString('8,000.00', $html);  // certified amount
    }

    // ── Real end-to-end generation path still succeeds for a non-GBP project ──

    public function test_certifying_a_non_gbp_project_still_generates_a_certificate_without_error(): void
    {
        $data = $this->makeOrgProjectContractApp('USD');
        $submitted = PaymentApplication::create([
            'project_id' => $data['project']->id, 'contract_id' => $data['contract']->id,
            'organization_id' => $data['org']->id, 'created_by' => $data['user']->id,
            'application_number' => 999, 'application_date' => now()->toDateString(),
            'gross_valuation' => 5000, 'amount_due' => 5000, 'status' => 'submitted',
        ]);
        Sanctum::actingAs($data['user']);

        $response = $this->postJson("/api/payment-applications/{$submitted->id}/certify", [
            'certified_amount' => 4800,
        ]);

        $response->assertStatus(200)->assertJson(['certificate_generated' => true]);
    }
}
