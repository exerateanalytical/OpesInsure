import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { Store } from "lucide-react-native";
import { AgentCard, AgentEmptyState, AgentSection, AgentShell, AgentSkeleton, AgentStatusChip } from "@/components/agent";
import { agentColors as c, agentType as T } from "@/theme/agent";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Card, Screen, StatusChip } from "@/components/ui";
import { purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { DistributionApi } from "@/api/workflow";
import { InstitutionMark } from "@/components/InstitutionMark";
import { useInsurerLogos } from "@/components/offers/useInsurerLogo";
import { useTranslation } from "@/i18n";
import { catalogueName, commissionPercent, groupCatalogue } from "@/lib/quoteWorkflow";

/** Agent / broker "What I can sell": GET distribution/catalogue, grouped by line, sellable first. */
export function SellableCatalogueScreen({ variant = "default" }: { variant?: "default" | "agent" }) {
  const { t, td, language } = useTranslation();
  const q = useLoad(() => DistributionApi.catalogue(), []);
  // Insurer logos from the public directory (the catalogue answer carries the name only).
  const logoFor = useInsurerLogos();
  const mark = (name?: string | null) => (
    <InstitutionMark logoUrl={logoFor(null, name)} initials={(name ?? "").split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join("").toUpperCase()} size={40} />
  );
  if (variant === "agent") {
    // Commercial Agent spec v2: drill-down shell, one card per line, sellable = green chip only.
    return (
      <AgentShell variant="drilldown" title={t("catTitle")} refreshing={q.loading && !!q.data} onRefresh={q.reload}>
        <Text style={a.subtitle}>{t("catSubtitle")}</Text>
        {q.loading && !q.data ? (
          <AgentSkeleton rows={4} height={72} />
        ) : q.error && !q.data ? (
          <AgentEmptyState icon={Store} title={t("catLoadError")} body={t("loadErrorBody")} actionLabel={t("agHomeRetry")} onAction={q.reload} />
        ) : !q.data || q.data.length === 0 ? (
          <AgentEmptyState icon={Store} title={t("catEmpty")} body={t("catEmptyBody")} />
        ) : (
          groupCatalogue(q.data, language).map((g) => (
            <AgentSection key={g.line} title={td(`line_${g.line}`, g.line)}>
              <AgentCard padded={false}>
                {g.items.map((i, n) => {
                  const rate = commissionPercent(i.commission_basis_points);
                  return (
                    <View key={i.product_id} style={[a.row, a.markRow, n > 0 && a.divider]}>
                      {mark(i.carrier_name)}
                      <View style={a.copy}>
                      <View style={a.top}>
                        <Text style={a.name}>{catalogueName(i, language)}</Text>
                        <AgentStatusChip status={i.sellable ? "Active" : "Inactive"} label={i.sellable ? t("catSellable") : t("catBlocked")} />
                      </View>
                      <Text style={a.meta}>{[i.carrier_name, rate ? t("catCommission", { rate }) : null, i.requires_carrier_approval ? t("catApproval") : null].filter(Boolean).join(" · ")}</Text>
                      {!i.sellable && i.reasons?.length ? <Text style={a.meta}>{i.reasons.map((r) => td(`sellReason_${r}`, r)).join(" · ")}</Text> : null}
                      </View>
                    </View>
                  );
                })}
              </AgentCard>
            </AgentSection>
          ))
        )}
      </AgentShell>
    );
  }
  return (
    <Screen>
      <AppHeader title={t("catTitle")} subtitle={t("catSubtitle")} back />
      <StatePanel {...q} onRetry={q.reload} loadingLabel={t("catLoading")} emptyTitle={t("catEmpty")} emptyMessage={t("catEmptyBody")}>
        {(items) => (
          <>
            {groupCatalogue(items, language).map((g) => (
              <Card key={g.line}>
                <Text style={ps.title}>{td(`line_${g.line}`, g.line)}</Text>
                {g.items.map((i) => {
                  const rate = commissionPercent(i.commission_basis_points);
                  return (
                    <View key={i.product_id} style={{ flexDirection: "row", alignItems: "center", gap: 12 }}>
                      {mark(i.carrier_name)}
                      <View style={{ flex: 1, gap: 4 }}>
                      <View style={ps.between}>
                        <Text style={[ps.body, { flex: 1 }]}>{catalogueName(i, language)}</Text>
                        <StatusChip label={i.sellable ? t("catSellable") : t("catBlocked")} tone={i.sellable ? "success" : "neutral"} />
                      </View>
                      <Text style={ps.meta}>{[i.carrier_name, rate ? t("catCommission", { rate }) : null, i.requires_carrier_approval ? t("catApproval") : null].filter(Boolean).join(" · ")}</Text>
                      {!i.sellable && i.reasons?.length ? <Text style={ps.meta}>{i.reasons.map((r) => td(`sellReason_${r}`, r)).join(" · ")}</Text> : null}
                      </View>
                    </View>
                  );
                })}
              </Card>
            ))}
          </>
        )}
      </StatePanel>
    </Screen>
  );
}

const a = StyleSheet.create({
  subtitle: { ...T.secondary, color: c.secondary, textAlign: "center", marginTop: -8 },
  row: { minHeight: 62, paddingHorizontal: 16, paddingVertical: 12, gap: 4, justifyContent: "center" },
  divider: { borderTopWidth: 1, borderTopColor: c.border },
  top: { flexDirection: "row", alignItems: "center", gap: 12 },
  markRow: { flexDirection: "row", alignItems: "center", gap: 12 },
  copy: { flex: 1, gap: 4 },
  name: { ...T.cardTitle, color: c.text, flex: 1 },
  meta: { ...T.secondary, color: c.secondary },
});
