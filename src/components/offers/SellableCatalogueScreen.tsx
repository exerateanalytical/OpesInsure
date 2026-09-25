import React from "react";
import { Text, View } from "react-native";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Card, Screen, StatusChip } from "@/components/ui";
import { purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { DistributionApi } from "@/api/workflow";
import { useTranslation } from "@/i18n";
import { catalogueName, commissionPercent, groupCatalogue } from "@/lib/quoteWorkflow";

/** Agent / broker "What I can sell": GET distribution/catalogue, grouped by line, sellable first. */
export function SellableCatalogueScreen() {
  const { t, td, language } = useTranslation();
  const q = useLoad(() => DistributionApi.catalogue(), []);
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
                    <View key={i.product_id} style={{ gap: 4 }}>
                      <View style={ps.between}>
                        <Text style={[ps.body, { flex: 1 }]}>{catalogueName(i, language)}</Text>
                        <StatusChip label={i.sellable ? t("catSellable") : t("catBlocked")} tone={i.sellable ? "success" : "neutral"} />
                      </View>
                      <Text style={ps.meta}>{[i.carrier_name, rate ? t("catCommission", { rate }) : null, i.requires_carrier_approval ? t("catApproval") : null].filter(Boolean).join(" · ")}</Text>
                      {!i.sellable && i.reasons?.length ? <Text style={ps.meta}>{i.reasons.map((r) => td(`sellReason_${r}`, r)).join(" · ")}</Text> : null}
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
