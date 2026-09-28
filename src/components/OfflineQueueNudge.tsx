import React, { useEffect } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { useRouter } from "expo-router";
import { ChevronRight, CloudUpload, RefreshCw, TriangleAlert } from "lucide-react-native";
import { AgentButton, AgentCard } from "@/components/agent";
import { useTranslation } from "@/i18n";
import { useResilience } from "@/store/resilience";
import { MAX_QUEUE_OPERATIONS } from "@/offline/vault";
import { queueHealth } from "@/offline/queueCipher";
import { agentColors as c, agentIcon, agentType as T } from "@/theme/agent";

/**
 * OPS-08: pending offline actions + "Sync now", shown well before the queue cap.
 * Agent Home only (AGENT_UI_SPEC_V2 §11 offline = persistent, non-blocking):
 * a white card, navy outline icon; amber only when the queue nears the cap.
 */
export function OfflineQueueNudge() {
  const { t } = useTranslation();
  const router = useRouter();
  const { queue, online, syncing, hydrate, syncNow } = useResilience();
  useEffect(() => void hydrate(), [hydrate]);
  const health = queueHealth(queue.length, MAX_QUEUE_OPERATIONS);
  if (health.level === "empty") return null;
  const urgent = health.level !== "pending";
  const Icon = urgent ? TriangleAlert : CloudUpload;
  const title = `${queue.length} / ${MAX_QUEUE_OPERATIONS} ${t("pending")}`;
  const body = urgent ? t("syncQueueNearCap") : t("syncQueuePendingBody");
  return (
    <AgentCard style={[s.card, urgent && s.urgent]}>
      <Pressable accessibilityRole="button" accessibilityLabel={`${title}, ${body}`} onPress={() => router.push("/sync")} style={({ pressed }) => [s.row, pressed && s.pressed]}>
        <Icon size={agentIcon.row} color={urgent ? c.warning : agentIcon.color} strokeWidth={agentIcon.stroke} />
        <View style={s.text}>
          <Text style={s.title}>{title}</Text>
          <Text style={s.body}>{body}</Text>
        </View>
        <ChevronRight size={agentIcon.small} color={c.muted} strokeWidth={agentIcon.stroke} />
      </Pressable>
      <AgentButton
        label={syncing ? t("syncing") : t("syncNow")}
        icon={RefreshCw}
        variant={urgent ? "primary" : "secondary"}
        loading={syncing}
        disabled={!online}
        onPress={() => void syncNow()}
      />
    </AgentCard>
  );
}

const s = StyleSheet.create({
  card: { gap: 12 },
  urgent: { borderColor: c.warning, backgroundColor: c.warningBg },
  row: { flexDirection: "row", alignItems: "center", gap: 12, minHeight: 48 },
  pressed: { opacity: 0.85 },
  text: { flex: 1, gap: 2 },
  title: { ...T.cardTitle, color: c.text },
  body: { ...T.secondary, color: c.secondary },
});
