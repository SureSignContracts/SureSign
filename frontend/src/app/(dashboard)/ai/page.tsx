'use client';

import { useState, useRef, useEffect } from 'react';
import { Brain, Send, Sparkles, FileSearch, PenLine, MessageSquareText, ShieldAlert, LockKeyhole } from 'lucide-react';
import api from '@/lib/api';
import { useAuthStore } from '@/store/authStore';
import { cn } from '@/lib/utils';

interface Message {
  id: string;
  role: 'user' | 'assistant';
  content: string;
  createdAt: Date;
}

const suggestions = [
  { label: 'Risk review', prompt: 'Summarise the key risks in this project', icon: ShieldAlert },
  { label: 'Draft a notice', prompt: 'Draft a variation notice for delay due to weather', icon: PenLine },
  { label: 'Contract guidance', prompt: 'What are my obligations under a construct-only contract?', icon: FileSearch },
  { label: 'RFI response', prompt: 'Help me write an RFI response for a design query', icon: MessageSquareText },
] as const;

export default function AiPage() {
  const { user } = useAuthStore();
  const [messages, setMessages] = useState<Message[]>([]);
  const [input, setInput] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const bottomRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages]);

  const sendMessage = async (text?: string) => {
    const content = text || input.trim();
    if (!content || isLoading) return;

    const userMsg: Message = { id: Date.now().toString(), role: 'user', content, createdAt: new Date() };
    setMessages(prev => [...prev, userMsg]);
    setInput('');
    setIsLoading(true);

    try {
      const { data } = await api.post('/ai/conversations', {
        message: content,
        context_type: 'general',
      });
      const reply = data.reply || data.message || 'I received your message. AI responses will be available once the API key is configured.';
      setMessages(prev => [...prev, {
        id: (Date.now() + 1).toString(),
        role: 'assistant',
        content: reply,
        createdAt: new Date(),
      }]);
    } catch {
      setMessages(prev => [...prev, {
        id: (Date.now() + 1).toString(),
        role: 'assistant',
        content: 'Unable to connect to the AI service. Please check your OpenAI API key in the settings.',
        createdAt: new Date(),
      }]);
    } finally {
      setIsLoading(false);
    }
  };

  const handleKey = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendMessage();
    }
  };

  return (
    <div className="flex h-full min-h-0 flex-col bg-[#f2f2f2]">
      {/* Header */}
      <div className="flex-shrink-0 px-4 pt-4 sm:px-6 sm:pt-6">
        <section className="relative mx-auto max-w-6xl overflow-hidden rounded-2xl bg-[#18211d] px-6 py-6 text-white shadow-[0_20px_55px_rgba(24,33,29,0.15)] sm:px-8">
          <div className="pointer-events-none absolute -right-20 -top-24 h-64 w-64 rounded-full bg-[#9ee5b5]/10 blur-3xl" />
          <div className="relative flex flex-wrap items-center justify-between gap-5">
            <div className="flex items-center gap-4">
              <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-[#9ee5b5] text-[#18211d]">
                <Brain size={19} />
              </div>
              <div>
                <p className="text-[10px] font-semibold uppercase tracking-[0.16em] text-[#9ee5b5]">Construction intelligence</p>
                <h1 className="mt-1 text-xl font-semibold tracking-[-0.025em]">SureSign Assistant</h1>
              </div>
            </div>
            <div className="flex items-center gap-2 rounded-xl bg-white/[0.055] px-3 py-2 text-[11px] text-white/45">
              <LockKeyhole size={13} className="text-[#9ee5b5]" />
              Project-aware workspace
            </div>
          </div>
        </section>
      </div>

      {/* Messages */}
      <div className="flex-1 overflow-y-auto px-4 py-5 sm:px-6">
        {messages.length === 0 ? (
          <div className="mx-auto grid h-full max-w-6xl items-center gap-8 py-4 lg:grid-cols-[0.8fr_1.2fr]">
            <div className="ss-animate-in max-w-md">
              <div className="mb-5 flex h-11 w-11 items-center justify-center rounded-xl bg-[#dff3e5] text-[#247044]"><Sparkles size={19} /></div>
              <p className="text-[10px] font-semibold uppercase tracking-[0.16em] text-[#3f8f60]">Ready to work</p>
              <h2 className="mt-3 text-3xl font-semibold leading-tight tracking-[-0.04em] text-[#18211d] sm:text-4xl">
                What needs moving today, {user?.name?.split(' ')[0]}?
              </h2>
              <p className="mt-4 max-w-md text-sm leading-6 text-[#667069]">Review risk, understand an obligation, or turn project context into clear contract correspondence.</p>
              <div className="mt-7 flex flex-wrap gap-2 text-[11px] text-[#667069]">
                {['Contract administration', 'Drafting', 'Project records'].map(item => <span key={item} className="rounded-lg bg-white px-3 py-2 shadow-[0_4px_14px_rgba(24,33,29,0.04)]">{item}</span>)}
              </div>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
              {suggestions.map(({ label, prompt, icon: SuggestionIcon }, index) => (
                <button key={prompt} onClick={() => sendMessage(prompt)}
                  className="group ss-animate-in min-h-[132px] rounded-2xl bg-white p-5 text-left shadow-[0_10px_30px_rgba(24,33,29,0.06)] transition duration-250 hover:-translate-y-1 hover:shadow-[0_18px_42px_rgba(24,33,29,0.1)]"
                  style={{ animationDelay: `${index * 60}ms` }}
                >
                  <div className="flex items-start justify-between gap-4">
                    <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-[#edf5ef] text-[#247044]"><SuggestionIcon size={16} /></span>
                    <span className="text-[10px] font-medium uppercase tracking-[0.13em] text-[#9aa39d]">0{index + 1}</span>
                  </div>
                  <p className="mt-4 text-[10px] font-semibold uppercase tracking-[0.13em] text-[#3f8f60]">{label}</p>
                  <p className="mt-1 text-sm font-medium leading-5 text-[#2f3833]">{prompt}</p>
                </button>
              ))}
            </div>
          </div>
        ) : (
          <div className="mx-auto max-w-4xl space-y-6 py-3">
            {messages.map((msg) => (
              <div key={msg.id} className={cn('flex gap-3', msg.role === 'user' && 'flex-row-reverse')}>
                <div className={cn(
                  'flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-xl text-xs font-medium',
                )}
                  style={msg.role === 'user'
                    ? { backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }
                    : { backgroundColor: '#18211d', color: '#9ee5b5' }
                  }
                >
                  {msg.role === 'user' ? (user?.name?.charAt(0) || 'U') : <Brain size={14} />}
                </div>
                <div className={cn('max-w-[82%] px-4 py-3 rounded-2xl text-sm leading-relaxed shadow-[0_6px_20px_rgba(24,33,29,0.05)]',
                  msg.role === 'user' ? 'rounded-tr-sm' : 'rounded-tl-sm'
                )}
                  style={msg.role === 'user'
                    ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
                    : { backgroundColor: '#ffffff', color: '#18211d' }
                  }
                >
                  {msg.content}
                </div>
              </div>
            ))}
            {isLoading && (
              <div className="flex gap-3">
                <div className="w-7 h-7 rounded-full flex items-center justify-center flex-shrink-0"
                     style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)' }}>
                  <Brain size={14} />
                </div>
                <div className="px-4 py-3 rounded-2xl rounded-tl-sm"
                     style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
                  <div className="flex gap-1">
                    {[0, 1, 2].map(i => (
                      <div key={i} className="w-1.5 h-1.5 rounded-full animate-bounce"
                           style={{ backgroundColor: 'var(--text-muted)', animationDelay: `${i * 0.15}s` }} />
                    ))}
                  </div>
                </div>
              </div>
            )}
            <div ref={bottomRef} />
          </div>
        )}
      </div>

      {/* Input */}
      <div className="flex-shrink-0 px-4 pb-5 sm:px-6">
        <div className="mx-auto flex max-w-4xl items-end gap-3 rounded-2xl bg-white p-2 shadow-[0_16px_44px_rgba(24,33,29,0.1)]">
          <div className="flex-1 overflow-hidden rounded-xl bg-[#f4f6f4]">
            <textarea
              value={input}
              onChange={e => setInput(e.target.value)}
              onKeyDown={handleKey}
              placeholder="Ask anything about your project, contracts, or documents..."
              rows={1}
              className="w-full resize-none bg-transparent px-4 py-3 text-sm outline-none"
              style={{ color: '#18211d', maxHeight: '120px' }}
            />
          </div>
          <button
            onClick={() => sendMessage()}
            disabled={!input.trim() || isLoading}
            className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-[#18211d] transition-all hover:-translate-y-0.5 hover:bg-[#26332d] disabled:opacity-30"
          >
            <Send size={16} className="text-[#9ee5b5]" />
          </button>
        </div>
      </div>
    </div>
  );
}
