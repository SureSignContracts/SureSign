import { useAuthStore } from '@/store/authStore';

/**
 * Returns permission flags for the current user within a project context.
 *
 * Security boundary is organisation vs organisation, not Client vs Admin —
 * every request is still authorised server-side against the user's own
 * organization_id (see backend authorize()/authorizeProject() methods).
 * Client accounts have full operational authority inside their own
 * organisation's projects; Super Admin / Admin are internal platform
 * operators, not the normal operators of customer projects (see AGENTS.md).
 *
 * Modules move off the legacy blanket `canWrite`/`readOnly` flags onto their
 * own `canManageX` flag as each module's permission review lands — until a
 * module's review lands its actions must keep using `canWrite`/`readOnly`
 * so behaviour doesn't change ahead of that module's own delete-rule and
 * workflow-state audit. Add a new `canManageX` flag here per module rather
 * than widening `canOperate` usage beyond what's been reviewed.
 */
export function useProjectPermissions() {
  const { hasRole } = useAuthStore();

  const isSuperAdmin = hasRole('Super Admin');
  const isAdmin      = hasRole('Admin');
  const isClient     = hasRole('Client');
  const isPlatformOperator = isSuperAdmin || isAdmin;

  /** Any authenticated member of the project's own organisation. */
  const canOperate = isClient || isPlatformOperator;

  // ── Legacy blanket flags — unchanged. No modules use these anymore as of
  // Batch 4 (every module has its own canManageX flag) — kept only so any
  // stray consumer doesn't silently break; new code should never read
  // these directly. ──────────────────────────────────────────────────────
  const canWrite = isPlatformOperator;
  const readOnly = isClient && !isPlatformOperator;

  // ── Reviewed modules — Client has full operational authority. ─────────
  /** Contracts: create/edit/upload/archive/restore. */
  const canManageContracts = canOperate;
  /** Trade Packages & Subcontract AI: create/edit/AI analysis/document generation. */
  const canManageTradePackages = canOperate;
  /** Variations: full operational workflow (create, submit, instruct, quote, assess, approve, reject, resubmit). */
  const canManageVariations = canOperate;
  /** RFIs: full CRUD, issue, respond, close, reopen. */
  const canManageRfis = canOperate;
  /** Meetings: create/edit/delete/attendees/attachments/minutes. */
  const canManageMeetings = canOperate;
  /** Site Reports: create/edit/delete/photos. */
  const canManageSiteReports = canOperate;
  /** Programme: full CRUD on milestones/activities/dependencies. */
  const canManageProgramme = canOperate;
  /** Delay Events: full CRUD. */
  const canManageDelayEvents = canOperate;
  /** EOT Requests: create/edit/submit/review/decide/close — Client has decision authority within its own org. */
  const canManageEotRequests = canOperate;
  /** Loss & Expense Claims: create/edit/submit/review/decide/close — Client has decision authority within its own org. */
  const canManageLossAndExpenseClaims = canOperate;
  /** Adjudication: full operational control (cases, documents, workflow, deadlines, timeline). */
  const canManageAdjudication = canOperate;
  /** Risks (project- and trade-package-scoped Risk Register): full CRUD. */
  const canManageRisks = canOperate;
  /** Delivery Documents (RAMS/Method Statements/ITPs/etc, project- and trade-package-scoped): full CRUD. */
  const canManageDeliveryDocuments = canOperate;
  /** Payment Applications: create/edit/submit/certify/mark-paid — Client has full commercial authority within its own org (final product decision, Batch 4). */
  const canManagePaymentApplications = canOperate;
  /** Payment Notices: issue. Once issued, backend locks edit/delete regardless of role — see PaymentNoticeController. */
  const canManagePaymentNotices = canOperate;
  /** Pay Less Notices: issue. Once issued, backend locks edit/delete regardless of role — see PayLessNoticeController. */
  const canManagePayLessNotices = canOperate;
  /** Final Accounts: create/revise/agree/sign/issue-certificate/close — workflow-state guards (isLocked/canTransition) are backend-enforced and unaffected by role. */
  const canManageFinalAccounts = canOperate;

  return {
    canWrite, readOnly, canOperate,
    canManageContracts, canManageTradePackages,
    canManageVariations, canManageRfis, canManageMeetings, canManageSiteReports,
    canManageProgramme, canManageDelayEvents, canManageEotRequests,
    canManageLossAndExpenseClaims, canManageAdjudication,
    canManageRisks, canManageDeliveryDocuments,
    canManagePaymentApplications, canManagePaymentNotices,
    canManagePayLessNotices, canManageFinalAccounts,
    isSuperAdmin, isAdmin, isClient, isPlatformOperator,
  };
}
