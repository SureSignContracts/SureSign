import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';

export interface RenderResult {
  rendered_prompt: string;
  placeholders_used: string[];
  missing_placeholders: string[];
}

interface UsePromptRendererOptions {
  templateId: number | null;
  projectId?: number | null;
  recordType?: string | null;
  recordId?: number | null;
  /** Determines the API prefix: 'admin' calls /admin/prompts, otherwise /prompts */
  adminRoute?: boolean;
  enabled?: boolean;
}

export function usePromptRenderer({
  templateId,
  projectId,
  recordType,
  recordId,
  adminRoute = false,
  enabled = true,
}: UsePromptRendererOptions) {
  const body: Record<string, unknown> = {};
  if (projectId)  body.project_id  = projectId;
  if (recordType && recordId) {
    body.record_type = recordType;
    body.record_id   = recordId;
  }

  const endpoint = adminRoute
    ? `/admin/prompts/templates/${templateId}/render`
    : `/prompts/${templateId}/render`;

  return useQuery<RenderResult>({
    queryKey: ['prompt-render', templateId, projectId ?? null, recordType ?? null, recordId ?? null],
    queryFn: () => api.post(endpoint, body).then(r => r.data),
    enabled: !!templateId && enabled,
    staleTime: 30_000,
  });
}
