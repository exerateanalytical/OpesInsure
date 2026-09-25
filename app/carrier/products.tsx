import React, { useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Button, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { errorMessage, Notice } from "@/components/portal/Workspace";
import { CarrierProduct, CarrierWorkspaceApi, humanize, shortDate } from "@/api/partner";
import { colors, space, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

export default function CarrierProducts() {
  const { t } = useTranslation();
  const q = useLoad(() => CarrierWorkspaceApi.products(), []);
  return (
    <Screen>
      <AppHeader title={t("caProducts")} subtitle={t("caProductsSubtitle")} back />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel={t("caLoadingProducts")}
        emptyTitle={t("caNoProducts")}
        emptyMessage={t("caNoProductsBody")}
      >
        {(rows) => (
          <>
            {rows.map((p) => (
              <ProductCard
                key={p.id}
                product={p}
                onChange={(next) => q.setData(rows.map((r) => (r.id === next.id ? next : r)))}
              />
            ))}
          </>
        )}
      </StatePanel>
    </Screen>
  );
}

function ProductCard({ product: p, onChange }: { product: CarrierProduct; onChange: (p: CarrierProduct) => void }) {
  const { t } = useTranslation();
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const active = p.status === "ACTIVE";
  const approved = p.tariffs.find((t) => t.status === "APPROVED");
  return (
    <Card>
      <View style={s.row}>
        <Text style={s.title}>{p.name}</Text>
        <StatusChip label={humanize(p.status)} tone={active ? "success" : p.status === "RETIRED" ? "neutral" : "info"} />
      </View>
      <Text style={s.meta}>
        {p.code} · v{p.version} · {p.line_code} · from {shortDate(p.effective_from)}
      </Text>
      <Text style={s.meta}>
        {approved ? t("caApprovedTariff", { version: approved.version }) : t("caNoApprovedTariff")} · {t("caPoliciesInForceCount", { count: p.policies_in_force })}
      </Text>
      {p.can_toggle ? (
        <>
          <TextField label={active ? t("caReasonPause") : t("caReasonResume")} value={reason} onChangeText={setReason} />
          <Notice text={error} tone="error" />
          <Button
            label={active ? t("caPauseSales") : t("caResumeSales")}
            variant={active ? "danger" : "primary"}
            loading={busy}
            disabled={reason.trim().length < 3}
            onPress={async () => {
              setBusy(true);
              setError(null);
              try {
                onChange(await CarrierWorkspaceApi.setProductActive(p.id, !active, reason.trim()));
                setReason("");
              } catch (e) {
                setError(errorMessage(e));
              } finally {
                setBusy(false);
              }
            }}
          />
        </>
      ) : null}
    </Card>
  );
}

const s = StyleSheet.create({
  row: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", gap: space.x2 },
  title: { ...type.cardTitle, color: colors.navy950, flex: 1 },
  meta: { ...type.meta, color: colors.neutral600 },
});
