<?php

namespace Database\Seeders\Demo;

use Illuminate\Database\Seeder;

/**
 * Single entry point for the SureSign demo environment (Halden Grove
 * Construction Ltd.), run only against the isolated 'demo' database
 * connection — never call this from the platform's DatabaseSeeder.
 *
 * Ordered strictly by dependency — organization before users, users before
 * a project can be created, project/contract before trade packages and
 * commercial records, etc. Each future phase adds its own seeder class and
 * appends it to this list — earlier phases are never rewritten, only
 * extended.
 *
 * Phase 1: organization, branding, users, roles/permissions.
 * Phase 2: the Riverside Wharf flagship project — contract, AI analysis,
 * trade packages, programme, risks, commercial history (variations,
 * payment applications, pay-less notice dispute, delay event/EOT/loss &
 * expense), site management (RFIs, site instructions, site diaries,
 * meetings, snags, QA reports), documents, and appointments.
 * Phase 3: the two projects that complete the lifecycle — Coldfield Retail
 * Park (near completion: draft final account, retention pending, active
 * snagging) and Priory Court Apartments (completed: agreed final account,
 * both retention moieties released, closed adjudication case, archived
 * documents).
 * Phase 4 (current): the remaining four projects from the approved
 * portfolio — Northgate Business Units (early construction), Elmsworth
 * Care Home Extension (pre-construction), Kingsmill Logistics Hub
 * (recently awarded, contract unsigned), and Aldermere Distribution Centre
 * (operationally difficult but professionally managed — overdue payment,
 * unresolved RFIs, an open escalating risk, a disputed variation, an
 * overdue EOT decision). The full seven-project portfolio from the
 * approved blueprint is now complete.
 */
class DemoEnvironmentSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // Phase 1 — company foundation
            DemoRoleSeeder::class,
            DemoOrganizationSeeder::class,
            DemoUserSeeder::class,
            // Generic, project-agnostic lookup data the Appointments module
            // needs — same seeder the real platform uses, safe to call here
            // since it's org-agnostic and idempotent (see DemoAppointmentSeeder).
            \Database\Seeders\AppointmentTypeSeeder::class,

            // Phase 2 — Riverside Wharf flagship project
            DemoProjectSeeder::class,
            DemoContractSeeder::class,
            DemoTradePackageSeeder::class,
            DemoProgrammeSeeder::class,
            DemoRiskSeeder::class,
            DemoCommercialSeeder::class,
            DemoSiteManagementSeeder::class,
            DemoDocumentSeeder::class,
            DemoAppointmentSeeder::class,

            // Phase 3 — the projects that complete the lifecycle
            DemoColdfieldSeeder::class,
            DemoPrioryCourtSeeder::class,

            // Phase 4 — the remaining portfolio
            DemoNorthgateSeeder::class,
            DemoElmsworthSeeder::class,
            DemoKingsmillSeeder::class,
            DemoAldermereSeeder::class,
        ]);
    }
}
