'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Settings2, Plus, X } from 'lucide-react';
import toast from 'react-hot-toast';
import api from '@/lib/api';
import { useAuthStore } from '@/store/authStore';
import Button from '@/components/ui/Button';
import Input from '@/components/ui/Input';
import Select from '@/components/ui/Select';
import { Badge } from '@/components/ui/Badge';
import EmptyState from '@/components/ui/EmptyState';

interface AppointmentType {
  id: number; name: string; slug: string; duration_minutes: number;
  is_active: boolean; is_public: boolean; requires_confirmation: boolean;
  meeting_method: string; display_order: number;
}

const EMPTY_FORM = {
  name: '', slug: '', description: '', duration_minutes: 30,
  is_public: false, is_active: true, requires_confirmation: false,
  meeting_method: 'tbc', display_order: 0,
};

export default function AppointmentTypesPage() {
  const router = useRouter();
  const qc = useQueryClient();
  const currentUser = useAuthStore(s => s.user);
  const isSuperAdmin = currentUser?.roles?.includes('Super Admin') ?? false;

  const [editing, setEditing] = useState<AppointmentType | null>(null);
  const [modalOpen, setModalOpen] = useState(false);
  const [form, setForm] = useState(EMPTY_FORM);
  const [formErrors, setFormErrors] = useState<Record<string, string[]>>({});

  // Defense in depth — the API is the real boundary (Super-Admin-only).
  useEffect(() => {
    if (currentUser && !isSuperAdmin) router.replace('/admin/appointments');
  }, [currentUser, isSuperAdmin, router]);

  const { data: types, isLoading } = useQuery({
    queryKey: ['appointment-types', 'all'],
    queryFn: () => api.get('/appointment-types').then(r => r.data as AppointmentType[]),
    enabled: isSuperAdmin,
  });

  const saveMutation = useMutation({
    mutationFn: (payload: typeof EMPTY_FORM) =>
      editing ? api.put(`/appointment-types/${editing.id}`, payload).then(r => r.data)
              : api.post('/appointment-types', payload).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['appointment-types'] });
      toast.success(editing ? 'Appointment type updated.' : 'Appointment type created.');
      closeModal();
    },
    onError: (err: any) => {
      const errors = err?.response?.data?.errors ?? {};
      setFormErrors(errors);
      if (!Object.keys(errors).length) toast.error(err?.response?.data?.message ?? 'Failed to save.');
    },
  });

  function openCreate() {
    setEditing(null);
    setForm(EMPTY_FORM);
    setFormErrors({});
    setModalOpen(true);
  }

  function openEdit(t: AppointmentType) {
    setEditing(t);
    setForm({ ...EMPTY_FORM, ...t } as typeof EMPTY_FORM);
    setFormErrors({});
    setModalOpen(true);
  }

  function closeModal() {
    setModalOpen(false);
    setEditing(null);
  }

  if (!isSuperAdmin) return null;

  return (
    <div className="space-y-5">
      <Link href="/admin/appointments" className="inline-flex items-center gap-1 text-sm" style={{ color: 'var(--text-muted)' }}>
        <ArrowLeft size={14} /> Back to Appointments
      </Link>

      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-xl font-bold flex items-center gap-2" style={{ color: 'var(--text-primary)' }}>
            <Settings2 size={20} /> Appointment Types
          </h1>
          <p className="text-sm mt-1" style={{ color: 'var(--text-muted)' }}>
            Platform-wide configuration — Super Admin only.
          </p>
        </div>
        <Button onClick={openCreate}><Plus size={14} /> New Type</Button>
      </div>

      <div className="rounded-2xl overflow-x-auto" style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <table className="w-full min-w-[700px]">
          <thead>
            <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
              {['Name', 'Duration', 'Confirmation', 'Visibility', 'Status', ''].map((h, i) => (
                <th key={i} className="text-left px-4 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              [...Array(3)].map((_, i) => (
                <tr key={i} style={{ borderBottom: '1px solid var(--border)', backgroundColor: 'var(--bg-surface)' }}>
                  {[...Array(6)].map((_, j) => (
                    <td key={j} className="px-4 py-3"><div className="h-4 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)', width: '60%' }} /></td>
                  ))}
                </tr>
              ))
            ) : !types || types.length === 0 ? (
              <tr><td colSpan={6}><EmptyState icon={Settings2} title="No appointment types yet" description="Create your first appointment type." /></td></tr>
            ) : types.map((t, idx) => (
              <tr key={t.id} style={{ borderBottom: idx < types.length - 1 ? '1px solid var(--border)' : undefined, backgroundColor: 'var(--bg-surface)' }}>
                <td className="px-4 py-3 text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{t.name}</td>
                <td className="px-4 py-3 text-sm" style={{ color: 'var(--text-secondary)' }}>{t.duration_minutes} min</td>
                <td className="px-4 py-3 text-sm" style={{ color: 'var(--text-secondary)' }}>{t.requires_confirmation ? 'Manual' : 'Automatic'}</td>
                <td className="px-4 py-3 text-sm" style={{ color: 'var(--text-secondary)' }}>{t.is_public ? 'Public' : 'Internal'}</td>
                <td className="px-4 py-3"><Badge tone={t.is_active ? 'success' : 'neutral'}>{t.is_active ? 'Active' : 'Inactive'}</Badge></td>
                <td className="px-4 py-3">
                  <button onClick={() => openEdit(t)} className="text-xs font-medium hover:underline" style={{ color: 'var(--gold)' }}>Edit</button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {modalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center backdrop-blur-sm p-4" style={{ backgroundColor: 'rgba(0,0,0,0.7)' }} onClick={closeModal}>
          <div onClick={e => e.stopPropagation()} className="w-full max-w-md rounded-2xl p-5 space-y-3 max-h-[90vh] overflow-y-auto" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
            <div className="flex items-center justify-between">
              <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{editing ? 'Edit Appointment Type' : 'New Appointment Type'}</h2>
              <button onClick={closeModal}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
            </div>

            <Input placeholder="Name" value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))} error={formErrors.name?.[0]} />
            <Input placeholder="Slug (e.g. product-walkthrough)" value={form.slug} onChange={e => setForm(f => ({ ...f, slug: e.target.value }))} error={formErrors.slug?.[0]} />
            <textarea
              placeholder="Description (optional)"
              value={form.description}
              onChange={e => setForm(f => ({ ...f, description: e.target.value }))}
              className="w-full px-3.5 py-2.5 rounded-lg text-sm outline-none resize-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
              rows={2}
            />

            <div className="grid grid-cols-2 gap-3">
              <Input type="number" placeholder="Duration (minutes)" value={form.duration_minutes} onChange={e => setForm(f => ({ ...f, duration_minutes: Number(e.target.value) }))} error={formErrors.duration_minutes?.[0]} />
              <Select value={form.meeting_method} onChange={e => setForm(f => ({ ...f, meeting_method: e.target.value }))}>
                <option value="tbc">To be confirmed</option>
                <option value="google_meet">Google Meet</option>
                <option value="teams">Microsoft Teams</option>
                <option value="zoom">Zoom</option>
                <option value="phone">Phone</option>
                <option value="in_person">In person</option>
                <option value="custom">Custom</option>
              </Select>
            </div>

            <label className="flex items-center gap-2 text-sm" style={{ color: 'var(--text-secondary)' }}>
              <input type="checkbox" checked={form.is_public} onChange={e => setForm(f => ({ ...f, is_public: e.target.checked }))} />
              Public (bookable outside internal management — future phase)
            </label>
            <label className="flex items-center gap-2 text-sm" style={{ color: 'var(--text-secondary)' }}>
              <input type="checkbox" checked={form.requires_confirmation} onChange={e => setForm(f => ({ ...f, requires_confirmation: e.target.checked }))} />
              Requires manual staff confirmation
            </label>
            <label className="flex items-center gap-2 text-sm" style={{ color: 'var(--text-secondary)' }}>
              <input type="checkbox" checked={form.is_active} onChange={e => setForm(f => ({ ...f, is_active: e.target.checked }))} />
              Active
            </label>

            <div className="flex gap-2 pt-2">
              <Button variant="secondary" className="flex-1" onClick={closeModal}>Cancel</Button>
              <Button className="flex-1" disabled={saveMutation.isPending} onClick={() => saveMutation.mutate(form)}>
                {saveMutation.isPending ? 'Saving…' : 'Save'}
              </Button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
