'use client';

import { useState, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { Save, Sparkles, Eye, EyeOff } from 'lucide-react';
import Toggle from '@/components/ui/Toggle';
import Button from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';

const KNOWN_AI_MODELS = [
  { value: 'claude-sonnet-5', label: 'claude-sonnet-5 (recommended)' },
  { value: 'claude-sonnet-4-6', label: 'claude-sonnet-4-6 (previous generation)' },
  { value: 'claude-haiku-4-5-20251001', label: 'claude-haiku-4-5-20251001 (faster / lower cost)' },
  { value: 'claude-opus-4-8', label: 'claude-opus-4-8 (most capable)' },
];

export default function AdminAiConfigPage() {
  const qc = useQueryClient();

  const [aiEnabled, setAiEnabled]           = useState<boolean | null>(null);
  const [promptsEnabled, setPromptsEnabled] = useState<boolean | null>(null);
  const [aiModel, setAiModel]               = useState<string | null>(null);
  const [aiEffort, setAiEffort]             = useState<string | null>(null);
  const [anthropicKey, setAnthropicKey]     = useState('');
  const [showAiKey, setShowAiKey]           = useState(false);
  const [aiSaved, setAiSaved]               = useState(false);

  const { data: suresignData } = useQuery({
    queryKey: ['admin-suresign-settings'],
    queryFn: () => api.get('/admin/suresign-settings').then(r => r.data?.data ?? {}),
  });

  useEffect(() => {
    if (!suresignData) return;
    if (aiEnabled === null)      setAiEnabled(!!(suresignData as any).ai_enabled);
    if (promptsEnabled === null) setPromptsEnabled((suresignData as any).prompts_enabled ?? true);
    if (aiModel === null)        setAiModel((suresignData as any).ai_model ?? 'claude-sonnet-5');
    if (aiEffort === null)       setAiEffort((suresignData as any).ai_effort ?? 'high');
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [suresignData]);

  const aiMutation = useMutation({
    mutationFn: (payload: any) => api.put('/admin/suresign-settings/ai', payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin-suresign-settings'] });
      setAnthropicKey('');
      setAiSaved(true);
      setTimeout(() => setAiSaved(false), 2500);
    },
  });

  const currentAiEnabled      = aiEnabled ?? !!(suresignData as any)?.ai_enabled;
  const currentPromptsEnabled = promptsEnabled ?? ((suresignData as any)?.prompts_enabled ?? true);
  const currentAiModel        = aiModel !== null ? aiModel : ((suresignData as any)?.ai_model ?? 'claude-sonnet-5');
  const currentAiEffort       = aiEffort !== null ? aiEffort : ((suresignData as any)?.ai_effort ?? 'high');
  const hasAnthropicKey       = !!(suresignData as any)?.has_anthropic_key;

  // A <select> silently displays its FIRST <option> whenever its bound value
  // doesn't match any listed option, while the real (unrecognized) value stays
  // selected underneath — misleadingly showing "claude-sonnet-5" as chosen when
  // the actual saved model is something else entirely (e.g. a retired alias).
  // Surfacing the true value here, instead of letting the dropdown lie, is what
  // this component was missing.
  const isKnownModel = KNOWN_AI_MODELS.some(m => m.value === currentAiModel);

  return (
    <div className="p-6 max-w-3xl mx-auto space-y-8">
      <div>
        <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>AI Configurations</h1>
        <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
          Configure the AI model and features available across the platform
        </p>
      </div>

      <section className="space-y-4">
        <div>
          <h2 className="text-sm font-semibold flex items-center gap-1.5" style={{ color: 'var(--text-secondary)' }}>
            <Sparkles size={13} />
            AI Assistant
          </h2>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
            Enable AI-assisted contract analysis and the Prompt Library. API keys are stored encrypted and never exposed to the frontend.
          </p>
        </div>

        <Card>
        <CardBody className="space-y-5">

          {/* Enable AI toggle */}
          <div className="flex items-center justify-between">
            <div>
              <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>Enable AI Analysis</p>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                Show &ldquo;Analyse Contract&rdquo; button when a contract file is uploaded. Used by Contract AI Analysis.
              </p>
            </div>
            <Toggle checked={currentAiEnabled} onChange={setAiEnabled} />
          </div>

          {/* Enable Prompt Library toggle */}
          <div className="flex items-center justify-between">
            <div>
              <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>Enable Prompt Library</p>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                Show prompt buttons on variations, RFIs, and other records for the copy/paste prompt workflow.
              </p>
            </div>
            <Toggle checked={currentPromptsEnabled} onChange={setPromptsEnabled} />
          </div>

          {/* Provider (currently only Anthropic) */}
          <div>
            <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
              Provider
            </label>
            <input
              type="text"
              value="Anthropic (Claude)"
              readOnly
              className="w-full px-3 py-2.5 rounded-lg text-sm outline-none opacity-60 cursor-not-allowed"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />
            <p className="text-xs mt-1.5" style={{ color: 'var(--text-muted)' }}>
              Anthropic is the only supported provider for Contract AI Analysis.
            </p>
          </div>

          {/* Model */}
          <div>
            <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
              Model
            </label>
            <select
              value={currentAiModel}
              onChange={e => setAiModel(e.target.value)}
              className="w-full px-3 py-2.5 rounded-lg text-sm outline-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: currentAiModel && !isKnownModel ? '#ef4444' : 'var(--text-primary)' }}
            >
              {!isKnownModel && currentAiModel && (
                <option value={currentAiModel}>{currentAiModel} (unrecognized — currently saved)</option>
              )}
              {KNOWN_AI_MODELS.map(m => (
                <option key={m.value} value={m.value}>{m.label}</option>
              ))}
            </select>
            {!isKnownModel && currentAiModel && (
              <p className="text-xs mt-1.5" style={{ color: '#ef4444' }}>
                This model isn&apos;t one of the supported options — it may be a retired or invalid model ID,
                which will cause every analysis to fail. Select a supported model above and save to fix this.
              </p>
            )}
          </div>

          {/* Effort */}
          <div>
            <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
              Effort
            </label>
            <select
              value={currentAiEffort}
              onChange={e => setAiEffort(e.target.value)}
              className="w-full px-3 py-2.5 rounded-lg text-sm outline-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            >
              <option value="low">Low (fastest, cheapest)</option>
              <option value="medium">Medium</option>
              <option value="high">High (recommended)</option>
              <option value="xhigh">X-High (deeper analysis, slower)</option>
              <option value="max">Max (most thorough, slowest / most expensive)</option>
            </select>
            <p className="text-xs mt-1.5" style={{ color: 'var(--text-muted)' }}>
              Controls how much the model reasons before answering. Higher effort can improve accuracy on complex contracts but costs more and takes longer.
            </p>
          </div>

          {/* Anthropic API Key */}
          <div>
            <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
              Anthropic API Key
            </label>
            <div className="relative">
              <input
                type={showAiKey ? 'text' : 'password'}
                value={anthropicKey}
                onChange={e => setAnthropicKey(e.target.value)}
                placeholder={hasAnthropicKey ? '••••••••  (key saved — enter new key to replace)' : 'sk-ant-…'}
                autoComplete="new-password"
                className="w-full px-3 py-2.5 pr-10 rounded-lg text-sm outline-none font-mono"
                style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
              />
              <button
                type="button"
                onClick={() => setShowAiKey(v => !v)}
                className="absolute right-3 top-1/2 -translate-y-1/2 opacity-50 hover:opacity-80"
                style={{ color: 'var(--text-muted)' }}
              >
                {showAiKey ? <EyeOff size={14} /> : <Eye size={14} />}
              </button>
            </div>
            <p className="text-xs mt-1.5" style={{ color: 'var(--text-muted)' }}>
              API keys are stored encrypted and never sent to the browser.
              {hasAnthropicKey && <span className="ml-1 text-green-600">A key is currently saved.</span>}
            </p>
          </div>

          <div className="flex justify-end pt-1">
            <Button
              type="button"
              onClick={() =>
                aiMutation.mutate({
                  ai_enabled: currentAiEnabled,
                  prompts_enabled: currentPromptsEnabled,
                  ai_model: currentAiModel,
                  ai_effort: currentAiEffort,
                  ...(anthropicKey ? { anthropic_api_key: anthropicKey } : {}),
                })
              }
              disabled={aiMutation.isPending}
              size="sm"
            >
              <Save size={12} />
              {aiSaved ? 'Saved!' : aiMutation.isPending ? 'Saving…' : 'Save AI Settings'}
            </Button>
          </div>
        </CardBody>
        </Card>
      </section>

      {/* Where AI is used today */}
      <section className="space-y-3">
        <h2 className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>Where this is used</h2>
        <div className="rounded-2xl p-5 text-sm space-y-2" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-secondary)' }}>
          <p><strong style={{ color: 'var(--text-primary)' }}>Contract AI Analysis</strong> — extracts payment terms, key dates, parties, and programme milestones from an uploaded contract, for an admin to confirm before it&rsquo;s used in payment date calculations.</p>
          <p><strong style={{ color: 'var(--text-primary)' }}>Prompt Library</strong> — a manual copy/paste workflow. Users copy an admin-managed prompt template with project context filled in, and paste it into an external AI tool. This makes no direct API calls of its own.</p>
        </div>
      </section>
    </div>
  );
}
