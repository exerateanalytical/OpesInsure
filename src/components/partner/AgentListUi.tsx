import React from "react";
import { StyleSheet, Text, View } from "react-native";
import type { LucideIcon } from "lucide-react-native";
import { AgentNavRow, AgentStatusChip, type AgentChipTone, type AgentStatusKey } from "@/components/agent";
import { useTranslation } from "@/i18n";
import { agentColors as c, agentType as T } from "@/theme/agent";

/** Server status codes that map onto the locked agent vocabulary (spec v2). */
const VOCAB: Record<string, AgentStatusKey> = {
  PAID: "Paid",
  SETTLED: "Paid",
  FAILED: "Failed",
  REJECTED: "Rejected",
  DECLINED: "Rejected",
  CANCELLED: "Cancelled",
  CANCELED: "Cancelled",
  EXPIRED: "Expired",
  PENDING: "Pending",
  PENDING_CLIENT: "Pending",
  UNDER_REVIEW: "Under Review",
  IN_REVIEW: "Under Review",
  PROCESSING: "Processing",
  REVERSED: "Reversed",
  ACCRUED: "Accrued",
  ACTIVE: "Active",
  SUSPENDED: "Suspended",
  REQUESTED: "Requested",
};

const SUCCESS = /APPROV|ISSUED|ACCEPT|BOUND|COMPLETE|CLOSED_PAID|VERIFIED|WON|CONVERTED/;
const DANGER = /FAIL|REJECT|DECLIN|LOST|FRAUD/;
const WARNING = /PENDING|AWAIT|REVIEW|ASSESS|HOLD|INFO|REOPEN|ACTION|EXPIRING/;
const NEUTRAL = /CANCEL|EXPIR|WITHDRAWN|CLOSED|DRAFT|NOT_REQUESTED/;

/** Chip props for a raw server status: locked word when it applies, otherwise the existing label in agent chip styling. */
export function agentChip(raw: string | null | undefined, label?: string | null): { status: AgentStatusKey; tone?: AgentChipTone; label?: string } {
  const code = String(raw ?? "").toUpperCase();
  const word = VOCAB[code];
  if (word) return { status: word };
  const tone: AgentChipTone = SUCCESS.test(code) ? "success" : DANGER.test(code) ? "danger" : WARNING.test(code) ? "warning" : NEUTRAL.test(code) ? "neutral" : "info";
  return { status: "Pending", tone, label: label ?? code.replaceAll("_", " ") };
}

/** AgentStatusChip for any server status (see agentChip). */
export function AgentRawChip({ raw, label, tone }: { raw: string | null | undefined; label?: string | null; tone?: AgentChipTone }) {
  const ch = agentChip(raw, label);
  return <AgentStatusChip status={ch.status} tone={tone ?? (ch.label ? ch.tone : undefined)} label={ch.label} />;
}

/** Agent list row: navy icon · title / meta · status chip, amount strongest on the right, chevron. */
export function AgentListRow({
  icon,
  title,
  subtitle,
  status,
  statusLabel,
  amount,
  first,
  onPress,
}: {
  icon: LucideIcon;
  title: string;
  subtitle?: string | null;
  status?: string | null;
  statusLabel?: string | null;
  amount?: string | null;
  first?: boolean;
  onPress?: () => void;
}) {
  const { td } = useTranslation();
  const ch = status ? agentChip(status, statusLabel) : null;
  const chipLabel = ch ? (ch.label ?? td(`agentSt_${ch.status.replace(/\s+/g, "")}`, ch.status)) : null;
  return (
    <AgentNavRow
      icon={icon}
      divider={!first}
      title={title}
      subtitle={subtitle}
      onPress={onPress}
      chevron={!!onPress}
      accessibilityLabel={[title, amount, chipLabel, subtitle].filter(Boolean).join(", ")}
      right={
        amount || ch ? (
          <View style={s.right}>
            {amount ? <Text style={s.amount} numberOfLines={1}>{amount}</Text> : null}
            {ch ? <AgentStatusChip status={ch.status} tone={ch.label ? ch.tone : undefined} label={ch.label} /> : null}
          </View>
        ) : undefined
      }
    />
  );
}

const s = StyleSheet.create({
  right: { alignItems: "flex-end", gap: 6, maxWidth: "48%" },
  amount: { ...T.cardTitle, fontFamily: "Inter_700Bold", color: c.heading },
});
