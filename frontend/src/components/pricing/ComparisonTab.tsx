'use client';

import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import Button from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import Select from '@/components/ui/Select';
import { ArrowUp, ArrowDown, Plus, Trash2 } from 'lucide-react';
import {
  PlanFeatureStatus, PricingFeature, PricingFeatureSection, PricingPlan, PricingPlanFeatureRow,
} from '@/types/pricing';

const STATUS_OPTIONS: PlanFeatureStatus[] = ['included', 'not_included', 'limited', 'custom'];

const inputClass = 'px-2.5 py-1.5 rounded-md text-sm outline-none';
const inputStyle = { backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' };

export default function ComparisonTab() {
  const qc = useQueryClient();
  const [newSectionName, setNewSectionName] = useState('');
  const [newFeatureName, setNewFeatureName] = useState<Record<number, string>>({});

  const { data: plans } = useQuery({
    queryKey: ['admin-pricing-plans'],
    queryFn: () => api.get('/admin/pricing/plans').then(r => r.data.data as PricingPlan[]),
  });

  const { data: sections, isLoading } = useQuery({
    queryKey: ['admin-pricing-feature-sections'],
    queryFn: () => api.get('/admin/pricing/feature-sections').then(r => r.data.data as PricingFeatureSection[]),
  });

  const { data: matrix } = useQuery({
    queryKey: ['admin-pricing-matrix'],
    queryFn: () => api.get('/admin/pricing/matrix').then(r => r.data.data as PricingPlanFeatureRow[]),
  });

  const invalidateSections = () => qc.invalidateQueries({ queryKey: ['admin-pricing-feature-sections'] });
  const invalidateMatrix = () => qc.invalidateQueries({ queryKey: ['admin-pricing-matrix'] });

  const createSection = useMutation({
    mutationFn: (name: string) => api.post('/admin/pricing/feature-sections', { name }),
    onSuccess: () => { setNewSectionName(''); invalidateSections(); },
  });
  const deleteSection = useMutation({
    mutationFn: (id: number) => api.delete(`/admin/pricing/feature-sections/${id}`),
    onSuccess: invalidateSections,
  });
  const reorderSections = useMutation({
    mutationFn: (order: number[]) => api.put('/admin/pricing/feature-sections/reorder', { order }),
    onSuccess: invalidateSections,
  });

  const createFeature = useMutation({
    mutationFn: ({ sectionId, name }: { sectionId: number; name: string }) =>
      api.post('/admin/pricing/features', { section_id: sectionId, name }),
    onSuccess: () => { invalidateSections(); invalidateMatrix(); },
  });
  const deleteFeature = useMutation({
    mutationFn: (id: number) => api.delete(`/admin/pricing/features/${id}`),
    onSuccess: () => { invalidateSections(); invalidateMatrix(); },
  });
  const reorderFeatures = useMutation({
    mutationFn: (order: number[]) => api.put('/admin/pricing/features/reorder', { order }),
    onSuccess: invalidateSections,
  });

  const updateCell = useMutation({
    mutationFn: (update: { plan_id: number; feature_id: number; status: PlanFeatureStatus; value_text?: string }) =>
      api.put('/admin/pricing/matrix', { updates: [update] }),
    onSuccess: invalidateMatrix,
  });

  const orderedSections = (sections || []).slice().sort((a, b) => a.order - b.order);
  const orderedPlans = (plans || []).slice().sort((a, b) => a.order - b.order);

  function cellFor(planId: number, featureId: number): PricingPlanFeatureRow | undefined {
    return (matrix || []).find(m => m.plan_id === planId && m.feature_id === featureId);
  }

  function moveSection(index: number, dir: -1 | 1) {
    const target = index + dir;
    if (target < 0 || target >= orderedSections.length) return;
    const order = orderedSections.map(s => s.id);
    [order[index], order[target]] = [order[target], order[index]];
    reorderSections.mutate(order);
  }

  function moveFeature(features: PricingFeature[], index: number, dir: -1 | 1) {
    const target = index + dir;
    if (target < 0 || target >= features.length) return;
    const order = features.map(f => f.id);
    [order[index], order[target]] = [order[target], order[index]];
    reorderFeatures.mutate(order);
  }

  if (isLoading) return <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Loading comparison table…</p>;

  return (
    <div className="space-y-6">
      <div className="flex gap-2">
        <input
          value={newSectionName}
          onChange={e => setNewSectionName(e.target.value)}
          placeholder="New section name (e.g. Core Platform)"
          className={inputClass}
          style={{ ...inputStyle, flex: 1 }}
        />
        <Button onClick={() => newSectionName.trim() && createSection.mutate(newSectionName.trim())}>
          <Plus size={14} /> Add Section
        </Button>
      </div>

      {orderedSections.map((section, sIndex) => {
        const features = (section.features || []).slice().sort((a, b) => a.order - b.order);
        return (
          <Card key={section.id}>
            <CardBody className="space-y-3">
              <div className="flex items-center justify-between">
                <h3 className="font-semibold" style={{ color: 'var(--text-primary)' }}>{section.name}</h3>
                <div className="flex gap-1.5">
                  <button onClick={() => moveSection(sIndex, -1)} disabled={sIndex === 0} className="p-1.5 rounded-md disabled:opacity-30" style={{ backgroundColor: 'var(--bg-elevated)' }}><ArrowUp size={13} /></button>
                  <button onClick={() => moveSection(sIndex, 1)} disabled={sIndex === orderedSections.length - 1} className="p-1.5 rounded-md disabled:opacity-30" style={{ backgroundColor: 'var(--bg-elevated)' }}><ArrowDown size={13} /></button>
                  <button onClick={() => { if (confirm(`Delete section "${section.name}" and all its features?`)) deleteSection.mutate(section.id); }} className="p-1.5 rounded-md" style={{ backgroundColor: 'var(--bg-elevated)', color: '#ef4444' }}><Trash2 size={13} /></button>
                </div>
              </div>

              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr>
                      <th className="text-left py-2 pr-4" style={{ color: 'var(--text-muted)' }}>Feature</th>
                      {orderedPlans.map(p => (
                        <th key={p.id} className="text-left py-2 px-2" style={{ color: 'var(--text-muted)' }}>{p.name}</th>
                      ))}
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    {features.map((feature, fIndex) => (
                      <tr key={feature.id} style={{ borderTop: '1px solid var(--border)' }}>
                        <td className="py-2 pr-4" style={{ color: 'var(--text-primary)' }}>
                          <div className="flex items-center gap-1">
                            <button onClick={() => moveFeature(features, fIndex, -1)} disabled={fIndex === 0} className="disabled:opacity-20"><ArrowUp size={12} /></button>
                            <button onClick={() => moveFeature(features, fIndex, 1)} disabled={fIndex === features.length - 1} className="disabled:opacity-20"><ArrowDown size={12} /></button>
                            {feature.name}
                          </div>
                        </td>
                        {orderedPlans.map(plan => {
                          const cell = cellFor(plan.id, feature.id);
                          return (
                            <td key={plan.id} className="py-2 px-2">
                              <Select
                                value={cell?.status || 'not_included'}
                                onChange={e => updateCell.mutate({ plan_id: plan.id, feature_id: feature.id, status: e.target.value as PlanFeatureStatus })}
                                size="sm"
                              >
                                {STATUS_OPTIONS.map(s => <option key={s} value={s}>{s.replace('_', ' ')}</option>)}
                              </Select>
                            </td>
                          );
                        })}
                        <td>
                          <button onClick={() => { if (confirm(`Delete feature "${feature.name}"?`)) deleteFeature.mutate(feature.id); }} style={{ color: '#ef4444' }}><Trash2 size={13} /></button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              <div className="flex gap-2 pt-1">
                <input
                  value={newFeatureName[section.id] || ''}
                  onChange={e => setNewFeatureName(p => ({ ...p, [section.id]: e.target.value }))}
                  placeholder="New feature name"
                  className={inputClass}
                  style={{ ...inputStyle, flex: 1 }}
                />
                <Button size="sm" variant="secondary" onClick={() => {
                  const name = (newFeatureName[section.id] || '').trim();
                  if (!name) return;
                  createFeature.mutate({ sectionId: section.id, name });
                  setNewFeatureName(p => ({ ...p, [section.id]: '' }));
                }}>
                  <Plus size={12} /> Add Feature
                </Button>
              </div>
            </CardBody>
          </Card>
        );
      })}
    </div>
  );
}
