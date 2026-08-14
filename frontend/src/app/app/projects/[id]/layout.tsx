'use client';

import { useState } from 'react';
import { useParams, usePathname, notFound } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';
import ProjectSidebar from '@/components/layout/ProjectSidebar';
import MobileTopBar from '@/components/layout/MobileTopBar';
import PendingTourLauncher from '@/components/tours/PendingTourLauncher';

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
          {children}
        </main>
      </div>
      <PendingTourLauncher />
    </div>
  );
}
