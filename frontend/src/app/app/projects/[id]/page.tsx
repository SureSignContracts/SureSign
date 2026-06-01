import { redirect } from 'next/navigation';

export default function ProjectRootPage({ params }: { params: { id: string } }) {
  redirect(`/app/projects/${params.id}/overview`);
}
