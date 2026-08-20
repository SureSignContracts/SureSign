'use client';

import { useState } from 'react';
import { useParams, usePathname, notFound } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';
import ProjectSidebar from '@/components/layout/ProjectSidebar';
import MobileTopBar from '@/components/layout/MobileTopBar';
import WorkspaceTransition from '@/components/layout/WorkspaceTransition';
import PendingTourLauncher from '@/components/tours/PendingTourLauncher';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { getProjectModuleLabel, resolveProjectOrganizationTitleName } from '@/lib/pageTitle';
import { useAuthStore } from '@/store/authStore';

export default function ProjectLayout({ children }: { children: React.ReactNode }) {
  const params = useParams();
  const pathname = usePathname();
  const projectId = params.id as string;
  const isSetupPage = pathname?.endsWith('/setup');
  const [navOpen, setNavOpen] = useState(false);

  const { data: project, isLoading, isError, error } = useQuery({
    queryKey: ['project', projectId],
    queryFn: () => api.get(`/projects/${projectId}`).then(r => r.data?.data ?? r.data),
    enabled: !!projectId,
    staleTime: 5 * 60 * 1000,
    retry: false,
  });

  // Browser tab title — reuses this same project fetch, plus the
  // already-in-memory authenticated user (no second request either way).
  // The Organisation segment prefers the same branded display name
  // Organisation-level pages use (`branding_settings.company_display_name`,
  // already loaded onto the auth store at login/fetchUser()) whenever the
  // viewer's own organisation IS this project's organisation — true for
  // every ordinary Client (tenant isolation means a Client only ever
  // reaches their own organisation's projects). It falls back to the plain
  // organisation name `ProjectController::show()` already returns
  // whenever that's not the case (e.g. a Super Admin/Admin viewing another
  // organisation's project) — never a second, organisation-scoped fetch,
  // and never another organisation's branding leaking onto this one.
  const { user } = useAuthStore();
  useDocumentTitle(getProjectModuleLabel(pathname), {
    project: project?.name,
    organization: resolveProjectOrganizationTitleName({
      projectOrganizationId: project?.organization_id,
      projectOrganizationName: project?.organization?.name,
      viewerOrganizationId: user?.organization?.id,
      viewerOrganizationBrandName: user?.organization?.branding?.company_display_name,
    }),
  });

  // A project that exists but belongs to another organization (403) must
  // look identical to one that doesn't exist (404) to a non-admin user —
  // otherwise the URL bar becomes a way to probe which project IDs belong
  // to other companies.
  if (isError) {
    const status = (error as { response?: { status?: number } })?.response?.status;
    if (status === 403 || status === 404) {
      notFound();
    }
  }

  return (
    <div className="flex h-screen overflow-hidden" style={{ backgroundColor: 'var(--bg-base)' }}>
      <ProjectSidebar
        projectId={projectId}
        projectName={project?.name}
        projectCode={project?.code}
        companyName={project?.organization?.name ?? project?.client?.name}
        organizationId={project?.organization_id}
        isLoading={isLoading && !project}
        mobileOpen={navOpen}
        onMobileClose={() => setNavOpen(false)}
        className={isSetupPage ? 'ss-setup-sidebar-in' : undefined}
      />
      <div className={`flex flex-col flex-1 min-w-0 overflow-hidden${isSetupPage ? ' ss-setup-workspace-in' : ''}`}>
        <MobileTopBar
          onMenu={() => setNavOpen(true)}
          title={project?.name || 'Project'}
          subtitle={project?.code || undefined}
          fallbackInitial={(project?.name || 'P').charAt(0).toUpperCase()}
        />
        <main className="flex-1 overflow-y-auto">
          <WorkspaceTransition>{children}</WorkspaceTransition>
        </main>
      </div>
      <PendingTourLauncher />
    </div>
  );
}
