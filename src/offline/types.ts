export type SyncState = "PENDING" | "SYNCING" | "FAILED" | "CONFLICT";

export type OfflineOperation = {
  id: string;
  kind: "DRAFT" | "MUTATION" | "UPLOAD";
  resource: string;
  resource_id?: string;
  method: "POST" | "PUT" | "PATCH";
  path: string;
  payload: Record<string, unknown>;
  state: SyncState;
  attempts: number;
  created_at: string;
  updated_at: string;
  next_attempt_at?: string;
  error_code?: string;
  conflict?: { server_version: number; local_version: number };
};

export type SyncSettings = {
  low_data_mode: boolean;
  wifi_only_uploads: boolean;
  compress_images: boolean;
};

export type SyncSummary = {
  pending: number;
  failed: number;
  conflicts: number;
  last_synced_at: string | null;
};
