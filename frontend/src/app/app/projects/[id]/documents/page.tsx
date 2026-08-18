'use client';

import { useParams } from 'next/navigation';
import ProjectDocumentsExplorer from '@/components/documents/ProjectDocumentsExplorer';
import FeatureAvailabilityGate from '@/components/feature-availability/FeatureAvailabilityGate';

function ProjectDocumentsPage() {
  return <ProjectDocumentsExplorer />;
}

export default function GatedProjectDocumentsPage() {
  const params = useParams<{ id: string }>();
  const id = params?.id as string;
  return (
    <FeatureAvailabilityGate featureKey="project.documents" title="Documents" backHref={`/app/projects/${id}/overview`} backLabel="Back to Project Overview">
      <ProjectDocumentsPage />
    </FeatureAvailabilityGate>
  );
}
