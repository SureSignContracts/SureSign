'use client';

import { useState } from 'react';
import { Wallet } from 'lucide-react';
import { Card, CardHeader, CardTitle, CardBody } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import EmptyState from '@/components/ui/EmptyState';
import PaginationBar from '@/components/ui/PaginationBar';
import { formatMoney } from '@/lib/currency';
import { formatDateTime } from '@/lib/dateTime';
import { useBillingPayments, minorToMajor } from '@/hooks/useBilling';

export default function PaymentListSection({ timeZone }: { timeZone?: string }) {
  const [page, setPage] = useState(1);
  const { data, isLoading, isError, isPlaceholderData } = useBillingPayments(page);

  return (
    <Card className="ss-animate-in">
      <CardHeader>
        <CardTitle>Payment History</CardTitle>
      </CardHeader>
      <CardBody>
        {isLoading ? (
          <div className="space-y-2">
            {[...Array(3)].map((_, i) => (
              <div key={i} className="h-10 rounded-lg animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
            ))}
          </div>
        ) : isError ? (
          <EmptyState icon={Wallet} title="Couldn't load payments" description="Something went wrong loading your payment history. Please try again shortly." />
        ) : !data || data.data.length === 0 ? (
          <EmptyState icon={Wallet} title="No payments yet" description="Payments will appear here once your subscription is active." />
        ) : (
          <div className="space-y-4" style={{ opacity: isPlaceholderData ? 0.6 : 1 }}>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left" style={{ color: 'var(--text-muted)' }}>
                    <th className="pb-2 font-medium text-xs">Reference</th>
                    <th className="pb-2 font-medium text-xs">Date</th>
                    <th className="pb-2 font-medium text-xs">Outcome</th>
                    <th className="pb-2 font-medium text-xs text-right">Amount</th>
                  </tr>
                </thead>
                <tbody>
                  {data.data.map(payment => (
                    <tr
                      key={payment.id}
                      className="transition-colors duration-150 hover:bg-[var(--bg-hover)]"
                      style={{ borderTop: '1px solid var(--border)' }}
                    >
                      <td className="py-2.5 font-medium" style={{ color: 'var(--text-primary)' }}>{payment.internal_reference}</td>
                      <td className="py-2.5" style={{ color: 'var(--text-secondary)' }}>
                        {payment.paid_at ? formatDateTime(payment.paid_at, { timeZone }) : '—'}
                      </td>
                      <td className="py-2.5">
                        <Badge status={payment.status} />
                        {payment.failure_message && (
                          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>{payment.failure_message}</p>
                        )}
                      </td>
                      <td className="py-2.5 text-right tabular-nums" style={{ color: 'var(--text-primary)' }}>
                        {formatMoney(minorToMajor(payment.amount), payment.currency)}
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
