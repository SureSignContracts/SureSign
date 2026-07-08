'use client';

import { Brain, Zap, Settings, ToggleLeft, ToggleRight } from 'lucide-react';
import { Card } from '@/components/ui/Card';

const AI_MODELS = [
  { id: 'gpt-4o', name: 'GPT-4o', provider: 'OpenAI', use: 'Document drafting, summarisation', status: 'active' },
  { id: 'gpt-4o-mini', name: 'GPT-4o Mini', provider: 'OpenAI', use: 'Lightweight tasks, quick responses', status: 'active' },
  { id: 'claude-3-5-sonnet', name: 'Claude 3.5 Sonnet', provider: 'Anthropic', use: 'Complex reasoning, analysis', status: 'inactive' },
];

const AI_FEATURES = [
  { id: 'meeting_minutes', label: 'Meeting Minute Generation', description: 'Auto-generate structured meeting minutes from transcripts' },
  { id: 'doc_summary', label: 'Document Summarisation', description: 'Summarise uploaded documents and contracts' },
  { id: 'variation_extraction', label: 'Variation Extraction', description: 'Identify potential variations from correspondence' },
  { id: 'email_parsing', label: 'Email Parsing', description: 'Parse emails to extract action items and notices' },
  { id: 'smart_reminders', label: 'Smart Reminders', description: 'AI-driven deadline and action reminders' },
];

export default function AdminAiConfigPage() {
  return (
    <div className="p-6 max-w-4xl mx-auto space-y-8">
      <div>
        <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>AI Configurations</h1>
        <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
          Manage AI models and features available across the platform
        </p>
      </div>

      {/* Models */}
      <section>
        <h2 className="text-sm font-semibold mb-3" style={{ color: 'var(--text-secondary)' }}>AI Models</h2>
        <div className="space-y-3">
          {AI_MODELS.map((model, i) => (
            <div
              key={model.id}
              className="flex items-center justify-between p-4 rounded-xl ss-animate-in"
              style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: `${Math.min(i * 45, 360)}ms` }}
            >
              <div className="flex items-center gap-4">
                <div className="w-9 h-9 rounded-xl flex items-center justify-center" style={{ backgroundColor: 'rgba(139,92,246,0.1)' }}>
                  <Brain size={16} style={{ color: '#8b5cf6' }} />
                </div>
                <div>
                  <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{model.name}</p>
                  <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{model.provider} · {model.use}</p>
                </div>
              </div>
              <div className="flex items-center gap-3">
                <span
                  className="text-xs px-2 py-0.5 rounded-full"
                  style={model.status === 'active'
                    ? { backgroundColor: 'rgba(34,197,94,0.1)', color: '#4ade80' }
                    : { backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)' }
                  }
                >
                  {model.status}
                </span>
                <button className="p-1.5 rounded-lg hover:bg-[var(--bg-hover)] transition-colors">
                  <Settings size={14} style={{ color: 'var(--text-muted)' }} />
                </button>
              </div>
            </div>
          ))}
        </div>
      </section>

      {/* Features */}
      <section>
        <h2 className="text-sm font-semibold mb-3" style={{ color: 'var(--text-secondary)' }}>AI Features</h2>
        <Card className="overflow-hidden">
          {AI_FEATURES.map((feature, i) => (
            <div
              key={feature.id}
              className="flex items-center justify-between px-5 py-4"
              style={{
                backgroundColor: 'var(--bg-surface)',
                borderBottom: i < AI_FEATURES.length - 1 ? '1px solid var(--border)' : 'none',
              }}
            >
              <div className="flex items-center gap-3">
                <Zap size={15} style={{ color: '#f59e0b' }} />
                <div>
                  <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{feature.label}</p>
                  <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{feature.description}</p>
                </div>
              </div>
              <ToggleRight size={22} style={{ color: '#4ade80', cursor: 'pointer' }} />
            </div>
          ))}
        </Card>
      </section>
    </div>
  );
}
