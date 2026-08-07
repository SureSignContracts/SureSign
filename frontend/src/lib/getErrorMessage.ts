// Backward-compatible re-export — the canonical implementation now lives in
// normalizeApiError.ts (Error Messaging & Recovery UX, Batch 1). Kept as its
// own file/name so the ~30 existing `import { getErrorMessage } from
// '@/lib/getErrorMessage'` call sites need no changes.
export { getErrorMessage } from './normalizeApiError';
