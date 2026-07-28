'use client';

import { useState } from 'react';
import { Receipt, ExternalLink } from 'lucide-react';
import { Card, CardHeader, CardTitle, CardBody } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import EmptyState from '@/components/ui/EmptyState';
import PaginationBar from '@/components/ui/PaginationBar';
import { formatMoney } from '@/lib/currency';
import { formatDateOnly } from '@/lib/dateTime';
import { useBillingInvoices, minorToMajor } from '@/hooks/useBilling';

export default function InvoiceListSection() {
  const [page, setPage] = useState(1);
  const { data, isLoading, isError, isPlaceholderData } = useBillingInvoices(page);

  return (
    <Card className="ss-animate-in">
      <CardHeader>
        <CardTitle>Recent Invoices</CardTitle>
      </CardHeader>
      <CardBody>
        {isLoading ? (
          <div className="space-y-2">
            {[...Array(3)].map((_, i) => (
              <div key={i} className="h-10 rounded-lg animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
            ))}
          </div>
        ) : isError ? (
          <EmptyState icon={Receipt} title="Couldn't load invoices" description="Something went wrong loading your invoices. Please try again shortly." />
        ) : !data || data.data.length === 0 ? (
          <EmptyState icon={Receipt} title="No invoices yet" description="Invoices will appear here once your subscription is active." />
        ) : (
          <div className="space-y-4" style={{ opacity: isPlaceholderData ? 0.6 : 1 }}>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left" style={{ color: 'var(--text-muted)' }}>
                    <th className="pb-2 font-medium text-xs">Reference</th>
                    <th className="pb-2 font-medium text-xs">Status</th>
                    <th className="pb-2 font-medium text-xs">Billing period</th>
                    <th className="pb-2 font-medium text-xs text-right">Amount paid</th>
                    <th className="pb-2 font-medium text-xs text-right">Amount due</th>
                    <th className="pb-2 font-medium text-xs" />
                  </tr>
                </thead>
                <tbody>
                  {data.data.map(invoice => (
                    <tr
                      key={invoice.id}
                      className="transition-colors duration-150 hover:bg-[var(--bg-hover)]"
                      style={{ borderTop: '1px solid var(--border)' }}
                    >
                      <td className="py-2.5 font-medium" style={{ color: 'var(--text-primary)' }}>
                        {invoice.invoice_number}
                        {invoice.provider_invoice_number && (
                          <div className="text-[11px] font-normal" style={{ color: 'var(--text-muted)' }}>
                            Stripe invoice {invoice.provider_invoice_number}
                          </div>
                        )}
                      </td>
                      <td className="py-2.5"><Badge status={invoice.status} /></td>
                      <td className="py-2.5" style={{ color: 'var(--text-secondary)' }}>
                        {invoice.period_starts_at && invoice.period_ends_at
                          ? `${formatDateOnly(invoice.period_starts_at.slice(0, 10))} – ${formatDateOnly(invoice.period_ends_at.slice(0, 10))}`
                          : '—'}
                      </td>
                      <td className="py-2.5 text-right tabular-nums" style={{ color: 'var(--text-primary)' }}>
                        {formatMoney(minorToMajor(invoice.amount_paid), invoice.currency)}
                      </td>
                      <td className="py-2.5 text-right tabular-nums" style={{ color: 'var(--text-secondary)' }}>
                        {formatMoney(minorToMajor(invoice.amount_due), invoice.currency)}
                      </td>
                      <td className="py-2.5 text-right">
                        {invoice.hosted_invoice_url && (
                          <a href={invoice.hosted_invoice_url} target="_blank" rel="noopener noreferrer"
                            className="inline-flex items-center gap-1 text-xs transition-all duration-150 hover:underline hover:opacity-70"
                            style={{ color: 'var(--text-muted)' }}>
                            View <ExternalLink size={11} />
                          </a>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <PaginationBar
              page={data.current_page}
              lastPage={data.last_page}
              total={data.total}
              perPage={data.per_page}
              onPage={setPage}
              onPerPage={() => {}}
              showPerPageSelect={false}
            />
          </div>
        )}
      </CardBody>
    </Card>
  );
}
