'use client';

import { useState, useRef, useEffect } from 'react';
import { Brain, Send, Plus, Sparkles, User } from 'lucide-react';
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
  'Summarise the key risks in this project',
  'Draft a variation notice for delay due to weather',
  'What are my obligations under a construct-only contract?',
  'Help me write an RFI response for a design query',
];

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
    <div className="flex flex-col h-full">
      {/* Header */}
      <div className="flex-shrink-0 px-6 py-4 flex items-center gap-3"
           style={{ borderBottom: '1px solid var(--border)' }}>
        <div className="w-9 h-9 rounded-xl flex items-center justify-center"
             style={{ backgroundColor: 'var(--gold-15)' }}>
          <Brain size={18} style={{ color: 'var(--gold)' }} />
        </div>
        <div>
          <h1 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>AI Assistant</h1>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Construction contract & administration assistant</p>
        </div>
      </div>

      {/* Messages */}
      <div className="flex-1 overflow-y-auto px-6 py-6">
        {messages.length === 0 ? (
          <div className="flex flex-col items-center justify-center h-full max-w-lg mx-auto text-center">
            <div className="w-16 h-16 rounded-2xl flex items-center justify-center mb-4"
                 style={{ backgroundColor: 'var(--gold-15)' }}>
              <Sparkles size={28} style={{ color: 'var(--gold)' }} />
            </div>
            <h2 className="text-lg font-semibold mb-2" style={{ color: 'var(--text-primary)' }}>
              Hi {user?.name?.split(' ')[0]}, how can I help?
            </h2>
            <p className="text-sm mb-8" style={{ color: 'var(--text-muted)' }}>
              I can help with contract administration, drafting documents, RFI responses, and more.
            </p>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 w-full">
              {suggestions.map((s) => (
                <button key={s} onClick={() => sendMessage(s)}
                  className="text-left px-4 py-3 rounded-xl text-xs transition-all hover:border-[var(--gold)]"
                  style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-secondary)' }}
                >
                  {s}
                </button>
              ))}
            </div>
          </div>
        ) : (
          <div className="max-w-2xl mx-auto space-y-6">
            {messages.map((msg) => (
              <div key={msg.id} className={cn('flex gap-3', msg.role === 'user' && 'flex-row-reverse')}>
                <div className={cn(
                  'w-7 h-7 rounded-full flex items-center justify-center flex-shrink-0 text-xs font-medium',
                )}
                  style={msg.role === 'user'
                    ? { backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }
                    : { backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)' }
                  }
                >
                  {msg.role === 'user' ? (user?.name?.charAt(0) || 'U') : <Brain size={14} />}
                </div>
                <div className={cn('max-w-[80%] px-4 py-3 rounded-2xl text-sm leading-relaxed',
                  msg.role === 'user' ? 'rounded-tr-sm' : 'rounded-tl-sm'
                )}
                  style={msg.role === 'user'
                    ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
                    : { backgroundColor: 'var(--bg-elevated)', color: 'var(--text-primary)', border: '1px solid var(--border)' }
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
      <div className="flex-shrink-0 px-6 py-4" style={{ borderTop: '1px solid var(--border)' }}>
        <div className="max-w-2xl mx-auto flex gap-3 items-end">
          <div className="flex-1 rounded-2xl overflow-hidden"
               style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
            <textarea
              value={input}
              onChange={e => setInput(e.target.value)}
              onKeyDown={handleKey}
              placeholder="Ask anything about your project, contracts, or documents..."
              rows={1}
              className="w-full px-4 py-3 bg-transparent text-sm resize-none outline-none"
              style={{ color: 'var(--text-primary)', maxHeight: '120px' }}
            />
          </div>
          <button
            onClick={() => sendMessage()}
            disabled={!input.trim() || isLoading}
            className="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 transition-all disabled:opacity-40"
            style={{ backgroundColor: 'var(--gold)' }}
          >
            <Send size={16} style={{ color: 'var(--accent-fg)' }} />
          </button>
        </div>
      </div>
    </div>
  );
}
