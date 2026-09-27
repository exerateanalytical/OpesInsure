import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { CloudOff, Clock } from "lucide-react-native";
import { StatusChip } from "@/components/ui";
import { useFormatters } from "@/hooks/useFormatters";
import { useTranslation } from "@/i18n";
import { OfflineOperation } from "@/offline/types";
import { useResilience } from "@/store/resilience";
import { colors, space, type } from "@/theme/tokens";

/** "Updated 10:42" line with stale/offline hint (REF-001). */
export function FreshnessNote({ lastUpdatedAt, stale }: { lastUpdatedAt: number | null; stale?: boolean }) {
  const { t } = useTranslation();
  const f = useFormatters();
  const online = useResilience((s) => s.online);
  if (lastUpdatedAt === null) return null;
  const Icon = online ? Clock : CloudOff;
  const label = !online
    ? t("freshOffline", { time: f.dateTime(new Date(lastUpdatedAt).toISOString()) })
    : t(stale ? "freshStale" : "freshUpdated", { time: f.dateTime(new Date(lastUpdatedAt).toISOString()) });
  return (
    <View style={styles.row} accessibilityRole="text" accessibilityLabel={label}>
      <Icon size={14} color={stale || !online ? colors.warningText : colors.neutral600} />
      <Text style={[styles.text, (stale || !online) && styles.warn]}>{label}</Text>
    </View>
  );
}

export type UniversalSyncState = "SAVED_LOCALLY" | "WAITING" | "SYNCING" | "SYNCED" | "CONFLICT" | "FAILED";

/** Maps a queued operation to the universal sync vocabulary (OFF-002). */
export function syncStateOf(op: Pick<OfflineOperation, "state" | "kind"> | null | undefined, syncing = false): UniversalSyncState {
  if (!op) return "SYNCED";
  if (op.state === "FAILED") return "FAILED";
  if (op.state === "CONFLICT") return "CONFLICT";
  if (op.state === "SYNCING" || syncing) return "SYNCING";
  return op.kind === "DRAFT" ? "SAVED_LOCALLY" : "WAITING";
}

const TONE: Record<UniversalSyncState, "neutral" | "success" | "warning" | "info" | "danger"> = {
  SAVED_LOCALLY: "neutral",
  WAITING: "info",
  SYNCING: "info",
  SYNCED: "success",
  CONFLICT: "warning",
  FAILED: "danger",
};
const KEY = {
  SAVED_LOCALLY: "syncSavedLocally",
  WAITING: "syncWaiting",
  SYNCING: "syncSyncing",
  SYNCED: "syncSynced",
  CONFLICT: "syncConflict",
  FAILED: "syncFailedAction",
} as const;

/** One chip for every offline-capable mutation (OFF-002). Text, not colour, carries meaning. */
export function SyncStateChip({ state }: { state: UniversalSyncState }) {
  const { t } = useTranslation();
  return <StatusChip label={t(KEY[state])} tone={TONE[state]} />;
}

const styles = StyleSheet.create({
  row: { flexDirection: "row", alignItems: "center", gap: space.x1, minHeight: 24 },
  text: { ...type.meta, color: colors.neutral600 },
  warn: { color: colors.warningText },
});
