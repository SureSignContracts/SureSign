'use client';

import { useParams, useRouter } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { useCurrencyFormatter } from '@/hooks/useCurrencyFormatter';
import { Calendar, DollarSign, MapPin, FileText, MessageSquare, GitBranch, Clock, AlertCircle, Activity } from 'lucide-react';

function InfoRow({ label, value }: { label: string; value?: string | null }) {
  return (
    <div className="flex items-start gap-3 py-2.5" style={{ borderBottom: '1px solid var(--border)' }}>
      <span className="text-xs min-w-[140px] pt-0.5" style={{ color: 'var(--text-muted)' }}>{label}</span>
      <span className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{value || '—'}</span>
    </div>
  );
}

function StatCard({
  label, value, color, icon: Icon, href,
}: {
  label: string; value: number | string; color: string; icon: React.ElementType; href?: string;
}) {
  const router = useRouter();
  return (
    <div
      onClick={() => href && router.push(href)}
      className="rounded-xl px-4 py-3 flex items-center gap-3 transition-all"
      style={{
        backgroundColor: 'var(--bg-elevated)',
        cursor: href ? 'pointer' : 'default',
        border: '1px solid transparent',
      }}
      onMouseEnter={e => { if (href) (e.currentTarget as HTMLElement).style.borderColor = color + '60'; }}
      onMouseLeave={e => { (e.currentTarget as HTMLElement).style.borderColor = 'transparent'; }}
    >
      <div className="w-9 h-9 rounded-lg flex items-center justify-center" style={{ backgroundColor: color + '18' }}>
        <Icon size={16} style={{ color }} />
      </div>
      <div>
        <div className="text-lg font-bold leading-none" style={{ color }}>{value}</div>
        <div className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{label}</div>
      </div>
    </div>
  );
}

const ACTIVITY_ICONS: Record<string, React.ElementType> = {
  project_created:                  Activity,
  contract_added:                   FileText,
  contract_updated:                 FileText,
  payment_application_created:      DollarSign,
  payment_application_submitted:    DollarSign,
  payment_application_certified:    DollarSign,
  payment_application_paid:         DollarSign,
  pdf_generated:                    FileText,
  rfi_created:                      MessageSquare,
  variation_created:                GitBranch,
};

const ACTIVITY_COLORS: Record<string, string> = {
  project_created:               '#4ade80',
  contract_added:                '#60a5fa',
  contract_updated:              '#60a5fa',
  payment_application_created:   '#f59e0b',
  payment_application_submitted: '#facc15',
  payment_application_certified: '#4ade80',
  payment_application_paid:      '#4ade80',
  pdf_generated:                 '#a78bfa',
  rfi_created:                   '#fb923c',
  variation_created:             '#8b5cf6',
};

function ActivityFeed({ activities }: { activities: any[] }) {
  if (activities.length === 0) {
    return (
      <div className="text-center py-8">
        <Activity size={24} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No activity yet</p>
      </div>
    );
  }
  return (
    <div className="space-y-0">
      {activities.slice(0, 8).map((a: any, i: number) => {
        const Icon = ACTIVITY_ICONS[a.activity_type] ?? Activity;
        const color = ACTIVITY_COLORS[a.activity_type] ?? 'var(--text-muted)';
        return (
          <div key={a.id} className="flex gap-3 py-2.5" style={{ borderBottom: i < activities.length - 1 ? '1px solid var(--border)' : 'none' }}>
            <div className="w-7 h-7 rounded-full flex-shrink-0 flex items-center justify-center mt-0.5" style={{ backgroundColor: color + '18' }}>
              <Icon size={13} style={{ color }} />
            </div>
            <div className="flex-1 min-w-0">
              <p className="text-xs font-medium truncate" style={{ color: 'var(--text-primary)' }}>{a.title}</p>
              {a.description && <p className="text-xs truncate" style={{ color: 'var(--text-muted)' }}>{a.description}</p>}
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                {a.user?.name} · {a.created_at ? new Date(a.created_at).toLocaleDateString() : ''}
              </p>
            </div>
          </div>
        );
      })}
    </div>
  );
}

export default function ProjectOverviewPage() {
  const formatCurrency = useCurrencyFormatter();
  const { id } = useParams<{ id: string }>();

  const { data: project, isLoading } = useQuery({
    queryKey: ['project', id],
    queryFn: () => api.get(`/projects/${id}`).then(r => r.data?.data ?? r.data),
    staleTime: 5 * 60 * 1000,
  });

  const { data: statsData } = useQuery({
    queryKey: ['project-stats', id],
    queryFn: () => api.get(`/projects/${id}/stats`).then(r => r.data).catch(() => null),
    enabled: !!id,
    staleTime: 2 * 60 * 1000,
  });

  const { data: activitiesData } = useQuery({
    queryKey: ['project-activities', id],
    queryFn: () => api.get(`/projects/${id}/activities`).then(r => r.data).catch(() => ({ data: [] })),
    enabled: !!id,
    staleTime: 1 * 60 * 1000,
  });

  const activities: any[] = activitiesData?.data ?? [];

  if (isLoading) {
    return (
      <div className="p-6 max-w-6xl mx-auto space-y-6">
        <div className="flex items-start justify-between">
          <div className="space-y-2">
            <div className="h-7 w-64 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
            <div className="h-4 w-40 rounded-lg animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
          </div>
          <div className="h-6 w-20 rounded-full animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
        </div>
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
          {[...Array(4)].map((_, i) => (
            <div key={i} className="h-16 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
          ))}
        </div>
        <div className="grid lg:grid-cols-2 gap-5">
          {[...Array(2)].map((_, i) => (
            <div key={i} className="rounded-2xl p-5 space-y-3 h-64 animate-pulse" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }} />
          ))}
        </div>
      </div>
    );
  }

  const statusColors: Record<string, { bg: string; text: string }> = {
    active:    { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
    on_hold:   { bg: 'rgba(234,179,8,0.12)',  text: '#facc15' },
    completed: { bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
    cancelled: { bg: 'rgba(239,68,68,0.12)',  text: '#f87171' },
  };
  const badge = statusColors[project?.status] ?? { bg: 'rgba(90,86,82,0.2)', text: '#9a9490' };

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6">
      {/* Header */}
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>
            {project?.name}
          </h1>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
            {project?.code} {project?.type ? `· ${project.type}` : ''}
          </p>
          {(project?.organization?.name || project?.client?.name) && (
            <p className="mt-1 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>
              Company: {project?.organization?.name ?? project?.client?.name}
            </p>
          )}
        </div>
        <span
          className="px-3 py-1 rounded-full text-xs font-medium capitalize"
          style={{ backgroundColor: badge.bg, color: badge.text }}
        >
          {project?.status?.replace(/_/g, ' ')}
        </span>
      </div>

      {/* Clickable stat cards */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <StatCard label="Open RFIs"          value={statsData?.open_rfis ?? 0}          color="#f59e0b" icon={MessageSquare} href={`/app/projects/${id}/rfis?status=open`} />
        <StatCard label="Pending Variations"  value={statsData?.pending_variations ?? 0}  color="#8b5cf6" icon={GitBranch}     href={`/app/projects/${id}/variations?status=pending`} />
        <StatCard label="Payment Apps"        value={statsData?.payment_apps ?? 0}        color="#10b981" icon={DollarSign}    href={`/app/projects/${id}/commercial`} />
        <StatCard label="Open Snagging"       value={statsData?.open_snagging ?? 0}       color="#ef4444" icon={AlertCircle}   href={`/app/projects/${id}/snagging`} />
      </div>

      {/* Contract value + certified summary */}
      {(statsData?.contract_value || statsData?.total_certified) && (
        <div className="grid grid-cols-2 gap-4">
          <div className="rounded-xl p-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Contract Value</p>
            <p className="text-xl font-bold mt-1" style={{ color: 'var(--gold)' }}>{formatCurrency(statsData?.contract_value ?? 0)}</p>
          </div>
          <div className="rounded-xl p-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Total Certified</p>
            <p className="text-xl font-bold mt-1" style={{ color: '#4ade80' }}>{formatCurrency(statsData?.total_certified ?? 0)}</p>
          </div>
        </div>
      )}

      <div className="grid lg:grid-cols-2 gap-5">
        {/* Project details */}
        <div className="rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
          <h2 className="text-sm font-semibold mb-4" style={{ color: 'var(--text-secondary)' }}>Project Details</h2>
          <InfoRow label="Project Name"    value={project?.name} />
          <InfoRow label="Project Number"  value={project?.code} />
          <InfoRow label="Type of Work"    value={project?.type} />
          <InfoRow label="Contract Type"   value={project?.contract_type} />
          <InfoRow label="Contract Value"  value={project?.contract_value ? formatCurrency(project.contract_value) : null} />
          <InfoRow label="Start Date"      value={project?.start_date ? formatDate(project.start_date) : null} />
          <InfoRow label="Completion"      value={project?.end_date ? formatDate(project.end_date) : null} />
          <InfoRow label="Address"         value={[project?.address, project?.city, project?.state, project?.postcode].filter(Boolean).join(', ')} />
        </div>

        {/* Client info */}
        <div className="rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
          <h2 className="text-sm font-semibold mb-4" style={{ color: 'var(--text-secondary)' }}>Client Information</h2>
          {project?.client ? (
            <>
              <InfoRow label="Client Name"    value={project.client.name} />
              <InfoRow label="Contact Name"   value={project.client.contact_name} />
              <InfoRow label="Email"          value={project.client.email} />
              <InfoRow label="Phone"          value={project.client.phone} />
              <InfoRow label="Address"        value={project.client.address} />
            </>
          ) : (
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No client linked to this project.</p>
          )}
        </div>
      </div>

      {/* Activity timeline */}
      <div className="rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>Recent Activity</h2>
          <Activity size={14} style={{ color: 'var(--text-muted)' }} />
        </div>
        <ActivityFeed activities={activities} />
      </div>

      {/* Description */}
      {project?.description && (
        <div className="rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
          <h2 className="text-sm font-semibold mb-3" style={{ color: 'var(--text-secondary)' }}>Description</h2>
          <p className="text-sm leading-relaxed" style={{ color: 'var(--text-secondary)' }}>
            {project.description}
          </p>
        </div>
      )}
    </div>
  );
}

