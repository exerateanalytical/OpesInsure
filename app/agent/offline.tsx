import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { CloudUpload, RefreshCw } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { AgentButton, AgentCard, AgentEmptyState, AgentShell, AgentSkeleton, AgentStatusChip, type AgentChipTone, type AgentStatusKey } from "@/components/agent";
import { AgentApi, type OfflineFieldItem } from "@/api/client";
import { useTranslation } from "@/i18n";
import type { CopyKey } from "@/i18n/strings";
import { agentColors as c, agentIcon, agentType as T } from "@/theme/agent";

const CHIP: Record<OfflineFieldItem["status"], [AgentStatusKey, AgentChipTone]> = {
  QUEUED: ["Pending", "warning"],
  SYNCING: ["Processing", "info"],
  FAILED: ["Failed", "danger"],
  SYNCED: ["Verified", "success"],
};

/** Offline field activity (drill-down): server-side view of the agent's queued work; failed items can be retried. */
export default function AgentOffline() {
  const { t } = useTranslation();
  const q = useLoad(() => AgentApi.offlineQueue(), []);
  const x = q.data ?? [];
  const setX = q.setData;
  const [busy, setBusy] = React.useState<string | null>(null);
  return (
    <AgentShell variant="drilldown" title={t("agOfflineFieldActivity")} refreshing={q.loading && !!q.data} onRefresh={q.reload}>
      <Text style={s.subtitle}>{t("agNothingSubmitted")}</Text>
      {q.loading && !q.data ? (
        <AgentSkeleton rows={3} height={72} />
      ) : q.error && !q.data ? (
        <AgentEmptyState icon={CloudUpload} title={t("agOfflineLoadError")} body={t("loadErrorBody")} actionLabel={t("agHomeRetry")} onAction={q.reload} />
      ) : x.length === 0 ? (
        <AgentEmptyState icon={CloudUpload} title={t("agOfflineEmpty")} body={t("agOfflineEmptyBody")} />
      ) : (
        <AgentCard padded={false}>
          {x.map((i, n) => {
            const [word, tone] = CHIP[i.status] ?? ["Pending", "neutral"];
            return (
              <View key={i.id} style={[s.row, n > 0 && s.divider]}>
                <View style={s.top}>
                  <CloudUpload size={agentIcon.row} color={agentIcon.color} strokeWidth={agentIcon.stroke} />
                  <View style={s.text}>
                    <Text style={s.title}>{i.type.replaceAll("_", " ")}</Text>
                    <Text style={s.meta}>{`${i.local_reference} · ${i.error ?? i.updated_at}`}</Text>
                  </View>
                  <AgentStatusChip status={word} tone={tone} label={t(`agOfflineSt_${i.status}` as CopyKey)} />
                </View>
                {i.status === "FAILED" ? (
                  <AgentButton
                    label={t("agRetrySecureSync")}
                    icon={RefreshCw}
                    variant="secondary"
                    loading={busy === i.id}
                    onPress={async () => {
                      setBusy(i.id);
                      try {
                        const v = await AgentApi.retryOffline(i.id);
                        setX(x.map((a) => (a.id === v.id ? v : a)));
                      } finally {
                        setBusy(null);
                      }
                    }}
                  />
                ) : null}
              </View>
            );
          })}
        </AgentCard>
      )}
    </AgentShell>
  );
}

const s = StyleSheet.create({
  subtitle: { ...T.secondary, color: c.secondary, textAlign: "center", marginTop: -8 },
  row: { minHeight: 62, paddingHorizontal: 16, paddingVertical: 14, gap: 12 },
  divider: { borderTopWidth: 1, borderTopColor: c.border },
  top: { flexDirection: "row", alignItems: "center", gap: 12 },
  text: { flex: 1, gap: 2 },
  title: { ...T.cardTitle, color: c.text, textTransform: "capitalize" },
  meta: { ...T.secondary, color: c.secondary },
});
