import React, { ReactNode } from "react";
import { StyleSheet, Text, View } from "react-native";
import { CloudOff, RefreshCw, WifiOff } from "lucide-react-native";
import { AgentButton, AgentCard, AgentSkeleton } from "@/components/agent";
import { useResilience } from "@/store/resilience";
import { useTranslation } from "@/i18n";
import { agentColors as c, agentIcon, agentType as T } from "@/theme/agent";

/** Persistent, non-blocking offline indicator for the agent security screens (spec §11). */
export function AgentOfflineNote() {
  const { t } = useTranslation();
  const online = useResilience((s) => s.online);
  if (online) return null;
  return (
    <View accessibilityRole="alert" style={s.offline}>
      <WifiOff size={agentIcon.small} color={c.warning} strokeWidth={agentIcon.stroke} />
      <Text style={s.offlineText}>{t("secOffline")}</Text>
    </View>
  );
}

/** Agent-styled load gate: skeleton while loading, short error + retry, else children(data). */
export function AgentLoadGate<T>({
  loading,
  error,
  data,
  onRetry,
  rows = 4,
  children,
}: {
  loading: boolean;
  error: unknown;
  data: T | null | undefined;
  onRetry: () => void;
  rows?: number;
  children: (data: T) => ReactNode;
}) {
  const { t } = useTranslation();
  if (data !== null && data !== undefined) return <>{children(data)}</>;
  if (loading || !error) return <AgentSkeleton rows={rows} />;
  return (
    <AgentCard style={s.error}>
      <CloudOff size={28} color={c.danger} strokeWidth={agentIcon.stroke} />
      <Text accessibilityRole="alert" style={s.errorTitle}>{t("loadErrorTitle")}</Text>
      <Text style={s.errorBody}>{t("loadErrorBody")}</Text>
      <AgentButton label={t("retry")} icon={RefreshCw} variant="secondary" onPress={onRetry} style={s.stretch} />
    </AgentCard>
  );
}

const s = StyleSheet.create({
  offline: { flexDirection: "row", alignItems: "center", gap: 8, backgroundColor: c.warningBg, borderRadius: 14, paddingHorizontal: 12, paddingVertical: 10 },
  offlineText: { ...T.secondary, color: c.text, flex: 1 },
  error: { alignItems: "center", gap: 8, paddingVertical: 24 },
  errorTitle: { ...T.cardTitle, color: c.heading, textAlign: "center" },
  errorBody: { ...T.secondary, color: c.secondary, textAlign: "center" },
  stretch: { alignSelf: "stretch", marginTop: 8 },
});
