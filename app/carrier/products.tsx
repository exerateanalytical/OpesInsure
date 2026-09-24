import React, { useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Button, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { errorMessage, Notice } from "@/components/portal/Workspace";
import { CarrierProduct, CarrierWorkspaceApi, humanize, shortDate } from "@/api/partner";
import { colors, space, type } from "@/theme/tokens";

export default function CarrierProducts() {
  const q = useLoad(() => CarrierWorkspaceApi.products(), []);
  return (
    <Screen>
      <AppHeader title="Products" subtitle="Your products, versions and tariffs" back />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel="Loading products…"
        emptyTitle="No products"
        emptyMessage="Products published for your company will appear here."
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
        {approved ? `Approved tariff v${approved.version}` : "No approved tariff"} · {p.policies_in_force} policies in force
      </Text>
      {p.can_toggle ? (
        <>
          <TextField label={active ? "Reason for pausing sales" : "Reason for resuming sales"} value={reason} onChangeText={setReason} />
          <Notice text={error} tone="error" />
          <Button
            label={active ? "Pause sales" : "Resume sales"}
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
