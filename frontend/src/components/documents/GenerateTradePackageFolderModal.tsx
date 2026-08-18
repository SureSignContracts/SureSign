'use client';

import { useMemo, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { X, Box } from 'lucide-react';
import toast from '@/lib/toast';
import api from '@/lib/api';
import Button from '@/components/ui/Button';
import { getErrorMessage } from '@/lib/getErrorMessage';

// ── constants ──────────────────────────────────────────────────────────────

const KNOWN_ACRONYMS: Record<string, string> = {
  cctv: 'CC',
  'm&e': 'ME',
  pv: 'PV',
  mep: 'MEP',
  grp: 'GRP',
};

// ── helpers ────────────────────────────────────────────────────────────────

function toTitleCase(input: string): string {
  return input
    .split(' ')
    .map((word) => {
      const lower = word.toLowerCase();
      if (KNOWN_ACRONYMS[lower]) return word.toUpperCase() === word ? word : KNOWN_ACRONYMS[lower];
      // Preserve known uppercased forms like CCTV, GRP
      const knownUpper = Object.values(KNOWN_ACRONYMS).find((v) => v === word.toUpperCase());
      if (knownUpper) return knownUpper;
      return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
    })
    .join(' ');
}

function deriveCode(name: string, usedCodes: Set<string>): string {
  const lower = name.toLowerCase().trim();
  const exact = KNOWN_ACRONYMS[lower];
  if (exact) {
    if (!usedCodes.has(exact)) return exact;
  }

  const words = name.trim().split(/\s+/);
  let candidate = words
    .map((word) => {
      const wl = word.toLowerCase();
      const acronym = KNOWN_ACRONYMS[wl];
      if (acronym) return acronym;
      return word.charAt(0).toUpperCase();
    })
    .join('')
    .toUpperCase();

  if (!usedCodes.has(candidate)) return candidate;

  let i = 1;
  while (usedCodes.has(`${candidate}-${String(i).padStart(2, '0')}`)) {
    i++;
  }
  return `${candidate}-${String(i).padStart(2, '0')}`;
}

// ── types ──────────────────────────────────────────────────────────────────

interface SelectedPackage {
  name: string;
  code: string;
  isCustom: boolean;
  originalName?: string;
}

interface GenerateResult {
  created: string[];
  skipped: string[];
}

interface Props {
  isOpen: boolean;
  onClose: () => void;
  projectReference: string;
  projectId: number;
  existingPackageNames: string[];
  onSuccess: (result: GenerateResult) => void;
  apiPath?: string;
  title?: string;
  description?: string;
}

// ── component ──────────────────────────────────────────────────────────────

export default function GenerateTradePackageFolderModal({
  isOpen,
  onClose,
  projectReference,
  projectId,
  existingPackageNames,
  onSuccess,
  apiPath,
  title = 'Generate Trade Package Folder',
  description = 'Select the trade packages you want to create for this project.',
}: Props) {
  const [checkedStandard, setCheckedStandard] = useState<Set<string>>(new Set());
  const [includeOther, setIncludeOther] = useState(false);
  const [customName, setCustomName] = useState('');
  const [result, setResult] = useState<GenerateResult | null>(null);

  const { data: catalogueData, isLoading: catalogueLoading } = useQuery({
    queryKey: ['trade-package-catalogue'],
    queryFn: () => api.get('/trade-packages/catalogue').then((r) => r.data),
    staleTime: 30 * 60 * 1000,
  });
  const standardPackages: { name: string; code: string }[] = catalogueData?.packages ?? [];

  if (!isOpen) return null;

  // All codes already in use (standard checked + existing from project)
  const existingCodes = useMemo(() => {
    const set = new Set<string>();
    standardPackages.forEach((pkg) => {
      if (existingPackageNames.some((n) => n.toLowerCase() === pkg.name.toLowerCase())) {
        set.add(pkg.code);
      }
    });
    checkedStandard.forEach((name) => {
      const pkg = standardPackages.find((p) => p.name === name);
      if (pkg) set.add(pkg.code);
    });
    return set;
  }, [checkedStandard, existingPackageNames, standardPackages]);

  const customCode = useMemo(() => {
    if (!customName.trim()) return '';
    return deriveCode(customName.trim(), existingCodes);
  }, [customName, existingCodes]);

  const selectedPackages = useMemo<SelectedPackage[]>(() => {
    const standard = standardPackages.filter((pkg) => checkedStandard.has(pkg.name)).map((pkg) => ({
      name: pkg.name,
      code: pkg.code,
      isCustom: false,
    }));
    if (includeOther && customName.trim()) {
      standard.push({ name: toTitleCase(customName.trim()), code: customCode, isCustom: true });
    }
    return standard;
  }, [checkedStandard, includeOther, customName, customCode, standardPackages]);

  const allChecked =
    standardPackages.length > 0 && checkedStandard.size === standardPackages.length && includeOther;

  const toggleAll = () => {
    if (allChecked) {
      setCheckedStandard(new Set());
      setIncludeOther(false);
    } else {
      setCheckedStandard(new Set(standardPackages.map((p) => p.name)));
      setIncludeOther(true);
    }
  };

  const toggleStandard = (name: string) => {
    setCheckedStandard((prev) => {
      const next = new Set(prev);
      if (next.has(name)) next.delete(name);
      else next.add(name);
      return next;
    });
  };

  const generateMutation = useMutation({
    mutationFn: async () => {
      if (selectedPackages.length === 0) throw new Error('Select at least one trade package.');
      const payload = {
        trade_packages: selectedPackages.map((pkg) => ({
          name: pkg.name,
          pkg_code: pkg.code,
          is_custom: pkg.isCustom ?? false,
          ...(pkg.isCustom ? { original_name: pkg.originalName ?? pkg.name } : {}),
        })),
      };
      const path = apiPath ?? `/admin/projects/${projectId}/subcontracts/generate-trade-packages`;
      const response = await api.post(path, payload);
      return response.data as GenerateResult;
    },
    onSuccess: (data) => {
      setResult(data);
      onSuccess(data);
      toast.success(`${data.created.length} package${data.created.length !== 1 ? 's' : ''} created`);
    },
    onError: (error: { response?: { data?: { message?: string } } }) => {
      toast.error(getErrorMessage(error, 'Failed to generate trade packages'));
    },
  });

  const handleClose = () => {
    setCheckedStandard(new Set());
    setIncludeOther(false);
    setCustomName('');
    setResult(null);
    onClose();
  };

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm"
      style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}
    >
      <div
        className="ss-animate-in max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-2xl"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}
      >
        {/* Header */}
        <div
          className="flex items-center justify-between p-5"
          style={{ borderBottom: '1px solid var(--border)' }}
        >
          <div>
            <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>
              {title}
            </h2>
            <p className="mt-1 text-xs" style={{ color: 'var(--text-muted)' }}>
              {description}
            </p>
          </div>
          <button onClick={handleClose} aria-label="Close">
            <X size={18} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>

        {result ? (
          /* Success state */
          <div className="space-y-4 p-5">
            <div
              className="rounded-xl p-4"
              style={{ backgroundColor: 'rgba(34,197,94,0.08)', border: '1px solid rgba(34,197,94,0.22)' }}
            >
              <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
                Trade packages created successfully
              </p>
              {result.created.length > 0 && (
                <ul className="mt-2 space-y-1">
                  {result.created.map((name) => (
                    <li key={name} className="text-xs" style={{ color: 'var(--text-secondary)' }}>
                      + {name}
                    </li>
                  ))}
                </ul>
              )}
              {result.skipped.length > 0 && (
                <div className="mt-3">
                  <p className="text-xs font-medium" style={{ color: 'var(--text-muted)' }}>
                    Skipped (already exist):
                  </p>
                  <ul className="mt-1 space-y-1">
                    {result.skipped.map((name) => (
                      <li key={name} className="text-xs" style={{ color: 'var(--text-muted)' }}>
                        {name}
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </div>
            <div className="flex justify-end">
              <Button type="button" onClick={handleClose}>
                Done
              </Button>
            </div>
          </div>
        ) : (
          <form
            onSubmit={(e) => {
              e.preventDefault();
              generateMutation.mutate();
            }}
            className="space-y-6 p-5"
          >
            {/* Section 1 — Standard packages */}
            <section className="space-y-3">
              <h3 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
                Standard packages
              </h3>

              {/* Select All */}
              <label className="flex cursor-pointer items-center gap-3 rounded-lg px-3 py-2 transition-colors hover:bg-[var(--bg-hover)]">
                <input
                  type="checkbox"
                  checked={allChecked}
                  onChange={toggleAll}
                  className="h-4 w-4 rounded accent-[var(--gold)]"
                />
                <span className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>
                  Select All
                </span>
              </label>

              <div
                className="rounded-xl divide-y overflow-hidden"
                style={{ border: '1px solid var(--border)', borderColor: 'var(--border)', boxShadow: 'var(--shadow-card)' }}
              >
                {catalogueLoading && (
                  <div className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)', backgroundColor: 'var(--bg-surface)' }}>
                    Loading packages…
                  </div>
                )}
                {standardPackages.map((pkg) => {
                  const alreadyExists = existingPackageNames.some(
                    (n) => n.toLowerCase() === pkg.name.toLowerCase()
                  );
                  return (
                    <label
                      key={pkg.name}
                      className={`flex cursor-pointer items-center gap-3 px-4 py-2.5 transition-colors ${alreadyExists ? 'opacity-50 cursor-not-allowed' : 'hover:bg-[var(--bg-hover)]'}`}
                      style={{ backgroundColor: 'var(--bg-surface)' }}
                    >
                      <input
                        type="checkbox"
                        checked={checkedStandard.has(pkg.name)}
                        disabled={alreadyExists}
                        onChange={() => !alreadyExists && toggleStandard(pkg.name)}
                        className="h-4 w-4 rounded accent-[var(--gold)]"
                      />
                      <span className="flex-1 text-sm" style={{ color: 'var(--text-primary)' }}>
                        {pkg.name}
                      </span>
                      <span
                        className="rounded px-1.5 py-0.5 text-xs font-mono font-medium"
                        style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }}
                      >
                        {pkg.code}
                      </span>
                      {alreadyExists && (
                        <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
                          exists
                        </span>
                      )}
                    </label>
                  );
                })}

                {/* Other */}
                <label
                  className="flex cursor-pointer items-center gap-3 px-4 py-2.5 transition-colors hover:bg-[var(--bg-hover)]"
                  style={{ backgroundColor: 'var(--bg-surface)' }}
                >
                  <input
                    type="checkbox"
                    checked={includeOther}
                    onChange={() => setIncludeOther((v) => !v)}
                    className="h-4 w-4 rounded accent-[var(--gold)]"
                  />
                  <span className="flex-1 text-sm" style={{ color: 'var(--text-primary)' }}>
                    Other (custom)
                  </span>
                  <span
                    className="rounded px-1.5 py-0.5 text-xs font-medium"
                    style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }}
                  >
                    Custom
                  </span>
                </label>
              </div>
            </section>

            {/* Section 2 — Custom package name */}
            {includeOther && (
              <section className="space-y-3">
                <h3 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
                  Custom package
                </h3>
                <div>
                  <label className="mb-1 block text-xs" style={{ color: 'var(--text-muted)' }}>
                    Trade package name
                  </label>
                  <input
                    type="text"
                    value={customName}
                    onChange={(e) => setCustomName(toTitleCase(e.target.value))}
                    placeholder="e.g. Fire Stopping"
                    className="w-full rounded-lg px-3 py-2 text-sm outline-none"
                    style={{
                      backgroundColor: 'var(--bg-base)',
                      border: '1px solid var(--border)',
                      color: 'var(--text-primary)',
                    }}
                  />
                  {customName.trim() && customCode && (
                    <p className="mt-1.5 text-xs" style={{ color: 'var(--text-muted)' }}>
                      Generated reference:{' '}
                      <span className="font-mono font-semibold" style={{ color: 'var(--gold)' }}>
                        {projectReference}-{customCode}
                      </span>
                    </p>
                  )}
                </div>
              </section>
            )}

            {/* Section 3 — Project reference */}
            <section className="space-y-1">
              <h3 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
                Project reference
              </h3>
              <p
                className="rounded-lg px-3 py-2 text-sm font-mono"
                style={{
                  backgroundColor: 'var(--bg-elevated)',
                  border: '1px solid var(--border)',
                  color: 'var(--text-secondary)',
                }}
              >
                {projectReference || '—'}
              </p>
            </section>

            {/* Section 4 — Preview */}
            {selectedPackages.length > 0 && (
              <section className="space-y-2">
                <h3 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
                  Package reference preview
                </h3>
                <div
                  className="overflow-hidden rounded-xl"
                  style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}
                >
                  <table className="w-full text-sm">
                    <thead>
                      <tr
                        style={{
                          backgroundColor: 'var(--bg-elevated)',
                          borderBottom: '1px solid var(--border)',
                        }}
                      >
                        <th
                          className="px-4 py-2.5 text-left text-xs font-medium"
                          style={{ color: 'var(--text-muted)' }}
                        >
                          Trade Package
                        </th>
                        <th
                          className="px-4 py-2.5 text-left text-xs font-medium"
                          style={{ color: 'var(--text-muted)' }}
                        >
                          Reference
                        </th>
                      </tr>
                    </thead>
                    <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
                      {selectedPackages.map((pkg) => (
                        <tr
                          key={pkg.name}
                          style={{ borderBottom: '1px solid var(--border)' }}
                        >
                          <td className="px-4 py-2.5">
                            <div className="flex items-center gap-2">
                              <span className="text-sm" style={{ color: 'var(--text-primary)' }}>
                                {pkg.name}
                              </span>
                              {pkg.isCustom && (
                                <span
                                  className="rounded px-1.5 py-0.5 text-xs font-medium"
                                  style={{
                                    backgroundColor: 'var(--gold-15)',
                                    color: 'var(--gold)',
                                  }}
                                >
                                  Custom
                                </span>
                              )}
                            </div>
                          </td>
                          <td className="px-4 py-2.5 font-mono text-xs font-semibold" style={{ color: 'var(--gold)' }}>
                            {projectReference}-{pkg.code}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </section>
            )}

            {/* Actions */}
            <div className="flex justify-end gap-3 pt-2">
              <button
                type="button"
                onClick={handleClose}
                className="rounded-lg px-4 py-2 text-sm"
                style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}
              >
                Cancel
              </button>
              <Button
                type="submit"
                disabled={generateMutation.isPending || selectedPackages.length === 0}
              >
                <Box size={14} />
                {generateMutation.isPending ? 'Generating…' : `Generate Package${selectedPackages.length !== 1 ? 's' : ''}`}
              </Button>
            </div>
          </form>
        )}
      </div>
    </div>
  );
}
