import { create } from 'zustand';

export type AiAnalysisStatus = 'pending' | 'processing' | 'completed' | 'failed' | 'confirmed' | 'cancelled' | null;

export interface AiAnalysisRecord {
  id: number;
  contract_id: number;
  project_id: number;
  status: AiAnalysisStatus;
  summary: string | null;
  started_at: string | null;
  completed_at: string | null;
  created_at: string;
  raw_response_json: any;
  confirmed_data_json: any;
  error_message: string | null;
  creator?: { id: number; name: string; email: string };
  contract?: { id: number; title: string };
}

interface AiAnalysisState {
  analysisId: number | null;
  contractId: number | null;
  contractTitle: string;
  projectId: string | null;
  status: AiAnalysisStatus;
  data: any;
  isMinimized: boolean;

  start: (params: { analysisId: number; contractId: number; contractTitle: string; projectId: string }) => void;
  openExisting: (record: AiAnalysisRecord, contractTitle: string, projectId: string) => void;
  updateStatus: (status: AiAnalysisStatus, data?: any) => void;
  minimize: () => void;
  restore: () => void;
  clear: () => void;
}

export const useAiAnalysisStore = create<AiAnalysisState>((set) => ({
  analysisId: null,
  contractId: null,
  contractTitle: '',
  projectId: null,
  status: null,
  data: null,
  isMinimized: false,

  start: ({ analysisId, contractId, contractTitle, projectId }) =>
    set({ analysisId, contractId, contractTitle, projectId, status: 'pending', isMinimized: false, data: null }),

  openExisting: (record, contractTitle, projectId) =>
    set({
      analysisId: record.id,
      contractId: record.contract_id,
      contractTitle,
      projectId,
      status: record.status,
      data: record.raw_response_json ?? record.confirmed_data_json,
      isMinimized: false,
    }),

  updateStatus: (status, data) =>
    set((s) => ({ status, data: data !== undefined ? data : s.data })),

  minimize: () => set({ isMinimized: true }),
  restore: () => set({ isMinimized: false }),

  clear: () =>
    set({ analysisId: null, contractId: null, contractTitle: '', projectId: null, status: null, data: null, isMinimized: false }),
}));
