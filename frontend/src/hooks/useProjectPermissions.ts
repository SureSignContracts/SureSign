import { useAuthStore } from '@/store/authStore';

/**
 * Returns permission flags for the current user within a project context.
 * Roles: Super Admin, Admin, Client
 * Client role = read-only (cannot create/edit/delete operational records).
 */
export function useProjectPermissions() {
  const { hasRole } = useAuthStore();

  const isSuperAdmin = hasRole('Super Admin');
  const isAdmin      = hasRole('Admin');
  const isClient     = hasRole('Client');

  /** Can create, edit, and delete operational records */
  const canWrite = isSuperAdmin || isAdmin;

  /** Can only view, not mutate */
  const readOnly = isClient && !isAdmin && !isSuperAdmin;

  return { canWrite, readOnly, isSuperAdmin, isAdmin, isClient };
}
