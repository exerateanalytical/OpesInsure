import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { useLoad } from "@/hooks/useLoad";
import { router, useLocalSearchParams } from "expo-router";
import { ContactRound, ShoppingBag } from "lucide-react-native";
import { AgentAvatar, AgentButton, AgentCard, AgentShell } from "@/components/agent";
import { allowedAction } from "@/lib/capabilities";
import { AgentApi } from "@/api/client";
import { shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";
import { Customer360Panel } from "@/components/crm/Customer360Panel";
import { ClientDocumentsCard } from "@/components/partner/ClientDocumentsCard";
import { AgentWorkspaceApi } from "@/api/partner";
import { ClientRelatedRecords } from "@/components/partner/ClientRelatedRecords";
import { KV } from "@/components/partner/AgentEarningsUi";
import { AgentRawChip } from "@/components/partner/AgentListUi";
import { BookLoad } from "@/components/partner/AgentBookUi";
import { agentColors as c, agentLayout as L, agentType as T } from "@/theme/agent";

/** Customer 360 (AGENT_UI_SPEC_V2 drill-down): profile card, book records, 360 overview, documents. */
export default function AgentClientDetail() {
  const { t, td } = useTranslation();
  const { id, partyId } = useLocalSearchParams<{ id: string; partyId?: string }>();
  const q = useLoad(() => AgentApi.client(id), [id]);
  const x = q.data;
  // allowed_actions (when sent) must list create_quote.
  const canSell = !!x && allowedAction(x, "create_quote", true);
  return (
    <AgentShell
      variant="drilldown"
      title={t("agClient")}
      refreshing={q.loading && !!x}
      onRefresh={q.reload}
      footer={canSell ? <AgentButton icon={ShoppingBag} label={t("agStartAssistedSale")} onPress={() => router.push(`/agent/sales/new?customerId=${id}`)} /> : undefined}
    >
      <BookLoad q={q} icon={ContactRound} rows={4}>
        {(d) => (
          <>
            <AgentCard>
              <View style={s.hero}>
                <AgentAvatar name={d.full_name} size={56} />
                <View style={s.heroText}>
                  <Text style={s.name} accessibilityRole="header">{d.full_name}</Text>
                  <AgentRawChip raw={d.kyc_status} label={td(`kycStatus_${d.kyc_status}`, d.kyc_status?.replaceAll("_", " "))} />
                </View>
              </View>
              <KV label={t("agClientPhone")} value={d.phone_e164} />
              <KV label={t("city")} value={d.city} />
              <KV label={t("agkActivePolicies")} value={String(d.active_policies ?? 0)} strong />
              <KV label={t("agkRenewalDue")} value={d.renewal_due_at ? shortDate(d.renewal_due_at) : t("agNone")} />
              <Text style={s.origin}>{d.origin_locked ? t("agOriginProtectedClient") : t("agOwnershipAwaiting")}</Text>
            </AgentCard>
            <ClientRelatedRecords
              variant="agent"
              customerId={id}
              base="/agent"
              loadPolicies={AgentWorkspaceApi.policies}
              loadProposals={AgentWorkspaceApi.proposals}
              loadClaims={AgentWorkspaceApi.claims}
            />
            <Customer360Panel variant="agent" partyId={d.party_id ?? partyId ?? null} />
            <ClientDocumentsCard variant="agent" customerId={id} load={AgentWorkspaceApi.clientDocuments} />
          </>
        )}
      </BookLoad>
    </AgentShell>
  );
}

const s = StyleSheet.create({
  hero: { flexDirection: "row", alignItems: "center", gap: 14, paddingBottom: 12 },
  heroText: { flex: 1, gap: 6 },
  name: { ...T.sectionTitle, color: c.heading },
  origin: { ...T.secondary, color: c.secondary, paddingTop: 12, borderTopWidth: 1, borderTopColor: c.border, marginTop: L.rowGap },
});
