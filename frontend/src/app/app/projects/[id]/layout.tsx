'use client';

import { useState } from 'react';
import { useParams } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';
import ProjectSidebar from '@/components/layout/ProjectSidebar';
import MobileTopBar from '@/components/layout/MobileTopBar';

export default function ProjectLayout({ children }: { children: React.ReactNode }) {
  const params = useParams();
  const projectId = params.id as string;
  const [navOpen, setNavOpen] = useState(false);

  const { data: project, isLoading } = useQuery({
    queryKey: ['project', projectId],
    queryFn: () => api.get(`/projects/${projectId}`).then(r => r.data?.data ?? r.data),
    enabled: !!projectId,
    staleTime: 5 * 60 * 1000,
  });

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
      />
      <div className="flex flex-col flex-1 min-w-0 overflow-hidden">
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
    </div>
  );
}
