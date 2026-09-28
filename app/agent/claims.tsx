import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { ShieldAlert } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { AgentEmptyState, AgentShell, AgentSkeleton } from "@/components/agent";
import { FilteredList } from "@/components/filters/FilteredList";
import { listSpec } from "@/components/filters/spec";
import { AgentWorkspaceApi, money, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";
import { agentColors as c, agentLayout as L, agentType as T } from "@/theme/agent";

/** Agent claims tab (spec v2 operational list): search + one filter icon, drill-down to tracking. */
export default function AgentClaims() {
  const { t, td } = useTranslation();
  const q = useLoad(() => AgentWorkspaceApi.claims(), []);
  return (
    <AgentShell refreshing={q.loading && !!q.data} onRefresh={q.reload}>
      <View style={s.head}>
        <Text accessibilityRole="header" style={s.title}>{t("claims")}</Text>
        <Text style={s.subtitle}>{t("ptClaimsSubtitle")}</Text>
      </View>
      {q.loading && !q.data ? (
        <AgentSkeleton rows={5} />
      ) : q.error ? (
        <AgentEmptyState icon={ShieldAlert} title={t("loadErrorTitle")} body={t("loadErrorBody")} actionLabel={t("retry")} onAction={q.reload} />
      ) : !q.data?.length ? (
        <AgentEmptyState icon={ShieldAlert} title={t("brNoClaims")} body={t("brNoClaimsBody")} />
      ) : (
        <FilteredList
          variant="agent"
          list="agent.claims"
          rows={q.data}
          {...listSpec(q.data, t, {
            status: (x) => x.status,
            statusLabel: (v) => td(`claimStatus_${v}`, v),
            dims: [{ key: "carrier", title: t("fltInsurer"), get: (x) => (x.carrier_name ? { value: x.carrier_name, label: x.carrier_name } : null) }],
            date: (x) => x.submitted_at,
            dateTitle: t("fltCreated"),
            amount: (x) => x.approved_amount_minor ?? x.estimated_loss_minor,
            name: (x) => x.customer_name,
          })}
          haystack={(x) => [x.claim_number, x.customer_name, x.policy_number, x.carrier_name, x.status]}
          placeholder={t("fltSearchQueue")}
          icon={ShieldAlert}
          render={(x) => ({
            title: x.customer_name,
            subtitle: [
              x.claim_number,
              x.carrier_name,
              x.approved_amount_minor !== null ? t("pdApproved") : x.estimated_loss_minor !== null ? t("pdEstimated") : null,
              `${t("pdFiled")} ${shortDate(x.submitted_at)}`,
            ]
              .filter(Boolean)
              .join(" · "),
            amount: x.approved_amount_minor !== null ? money(x.approved_amount_minor) : x.estimated_loss_minor !== null ? money(x.estimated_loss_minor) : null,
            statusCode: x.status,
            status: td(`claimStatus_${x.status}`, x.status.replaceAll("_", " ")),
          })}
          onPress={(x) => router.push({ pathname: "/agent/claims/[id]", params: { id: x.id } })}
        />
      )}
    </AgentShell>
  );
}

const s = StyleSheet.create({
  head: { gap: 4, marginBottom: L.rowGap },
  title: { ...T.screenTitle, color: c.heading },
  subtitle: { ...T.secondary, color: c.secondary },
});
