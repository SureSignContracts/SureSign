/**
 * Customer-facing labels for `projects.organization_role`
 * (backend/app/Support/Projects/ProjectOrganizationRole.php) — how the
 * owning Organization is acting on a specific Project. Deliberately
 * independent of the SureSign user role, Organization identity, and
 * Contract/TradePackage party fields — see that class's own docblock.
 *
 * Never render the raw stored value directly in the UI; use
 * PROJECT_ORGANIZATION_ROLE_LABELS (or projectOrganizationRoleLabel()) so a
 * future canonical-value addition only needs updating in one place. This
 * module is also the one the future Contract-Assisted Project Setup wizard
 * should reuse rather than re-declaring the mapping.
 */

export type ProjectOrganizationRole = 'main_contractor' | 'subcontractor' | 'employer' | 'consultant' | 'other';

export const PROJECT_ORGANIZATION_ROLE_OPTIONS: { value: ProjectOrganizationRole; label: string }[] = [
  { value: 'main_contractor', label: 'Main / General Contractor' },
  { value: 'subcontractor',   label: 'Subcontractor / Specialist Contractor' },
  { value: 'employer',        label: 'Employer / Owner' },
  { value: 'consultant',      label: 'Consultant' },
  { value: 'other',           label: 'Other' },
];

export const PROJECT_ORGANIZATION_ROLE_LABELS: Record<string, string> = Object.fromEntries(
  PROJECT_ORGANIZATION_ROLE_OPTIONS.map(({ value, label }) => [value, label])
);

/** Falls back to "Role not set" for null/undefined/unrecognised values — never a raw stored string. */
export function projectOrganizationRoleLabel(role: string | null | undefined): string {
  if (!role) return 'Role not set';
  return PROJECT_ORGANIZATION_ROLE_LABELS[role] ?? 'Role not set';
}
