// API layer for the Batch 3 public consultation pages (view + published
// summary) — the Consultancy-only counterpart to publicAppointments.ts.
// Reuses that file's generic signed-link request/error handling
// (request(), parseError(), signedQueryFrom(), isPastExpiry()) rather than
// re-implementing it; only the Consultancy-specific response shapes and
// endpoints are new here.

import { API_BASE, isPastExpiry, request, signedQueryFrom, type ApiResult } from './publicAppointments';

export type { ApiResult };

export interface ConsultancyServiceRef {
  display_name: string | null;
}

export interface ConsultantRef {
  name: string | null;
}

export interface ConsultationMeeting {
  status: 'available' | 'pending' | 'temporarily_unavailable' | 'unavailable';
  join_url: string | null;
}

export interface ConsultationPublicView {
  reference: string;
  status: string;
  starts_at: string;
  ends_at: string;
  booking_timezone: string;
  attendee_name: string;
  consultancy_service: ConsultancyServiceRef | null;
  assigned_consultant: ConsultantRef | null;
  customer_summary_published: boolean;
  customer_summary_published_at: string | null;
  meeting: ConsultationMeeting;
  ics_url: string | null;
  summary_url: string | null;
}

export interface ConsultationPublicSummary {
  reference: string;
  title: string | null;
  consultancy_service: ConsultancyServiceRef | null;
  assigned_consultant: ConsultantRef | null;
  starts_at: string;
  booking_timezone: string;
  summary: string | null;
  published_at: string | null;
}

export { isPastExpiry, signedQueryFrom };

export async function fetchConsultationView(token: string, searchParams: URLSearchParams): Promise<ApiResult<ConsultationPublicView>> {
  const signed = signedQueryFrom(searchParams);
  if (!signed) {
    return { ok: false, kind: 'invalid', message: 'This link is no longer active. Please use the latest email we sent you.' };
  }
  return request<ConsultationPublicView>(`${API_BASE}/public/consultations/${token}/view?${signed.toString()}`, signed);
}

export async function fetchConsultationSummary(token: string, searchParams: URLSearchParams): Promise<ApiResult<ConsultationPublicSummary>> {
  const signed = signedQueryFrom(searchParams);
  if (!signed) {
    return { ok: false, kind: 'invalid', message: 'This link is no longer active. Please use the latest email we sent you.' };
  }
  return request<ConsultationPublicSummary>(`${API_BASE}/public/consultations/${token}/summary?${signed.toString()}`, signed);
}
