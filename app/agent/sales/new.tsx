import React, { useEffect, useState } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { Pressable, StyleSheet, Text } from "react-native";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { AgentApi, AgentClient } from "@/api/client";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { colors, radius, space, type } from "@/theme/tokens";
const products = [
  "Motor Third Party",
  "Motor Comprehensive",
  "Travel",
  "Health",
];
export default function AgentSaleNew() {
  const { customerId } = useLocalSearchParams<{ customerId?: string }>();
  const q = useLoad(() => AgentApi.clients(), []);
  const clients: AgentClient[] = q.data ?? [];
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [client, setClient] = useState(customerId ?? "");
  const [product, setProduct] = useState(products[0]!);
  const [phone, setPhone] = useState("+237");
  useEffect(() => {
    if (!customerId || !q.data) return;
    const c = q.data.find((v) => v.id === customerId);
    if (c) setPhone(c.phone_e164);
  }, [customerId, q.data]);
  return (
    <Screen>
      <AppHeader
        title="Assisted insurance sale"
        subtitle="The client authorizes payment on their own phone"
        back
      />
      <Card>
        <Text style={s.label}>Client</Text>
        {q.loading || q.error || clients.length === 0 ? (
          <StatePanel
            {...q}
            onRetry={q.reload}
            loadingLabel="Loading clients…"
            emptyTitle="No clients yet"
            emptyMessage="Register a client before starting an assisted sale."
          >
            {() => null}
          </StatePanel>
        ) : null}
        {clients.map((c) => (
          <Pressable
            key={c.id}
            style={[s.option, client === c.id && s.selected]}
            onPress={() => {
              setClient(c.id);
              setPhone(c.phone_e164);
            }}
          >
            <Text style={s.optionText}>
              {c.full_name} · {c.phone_e164}
            </Text>
          </Pressable>
        ))}
        <Text style={s.label}>Product</Text>
        {products.map((p) => (
          <Pressable
            key={p}
            style={[s.option, product === p && s.selected]}
            onPress={() => setProduct(p)}
          >
            <Text style={s.optionText}>{p}</Text>
          </Pressable>
        ))}
        <TextField
          label="Client payment phone"
          keyboardType="phone-pad"
          value={phone}
          onChangeText={setPhone}
        />
        <Text style={s.note}>
          The agent must never collect or enter the client’s Mobile Money PIN.
        </Text>
        <Button
          label="Create quote and review"
          disabled={!client || phone.length < 8}
          loading={busy}
          onPress={async () => {
            setBusy(true);
            setError(null);
            try {
              const x = await AgentApi.createSale({
                customer_id: client,
                product,
                payment_phone_e164: phone,
              });
              router.replace(`/agent/sales/${x.id}`);
            } catch {
              setError("The sale could not be created. Check the connection and try again.");
            } finally {
              setBusy(false);
            }
          }}
        />
        {error ? (
          <Text accessibilityRole="alert" style={s.error}>
            {error}
          </Text>
        ) : null}
      </Card>
    </Screen>
  );
}
const s = StyleSheet.create({
  label: { ...type.label, color: colors.navy950 },
  option: {
    minHeight: 48,
    padding: space.x3,
    borderWidth: 1,
    borderColor: colors.neutral300,
    borderRadius: radius.control,
    justifyContent: "center",
  },
  optionText: { ...type.body, color: colors.navy950 },
  note: { ...type.meta, color: colors.neutral600 },
  error: { ...type.meta, color: colors.dangerText },
  selected: { borderColor: colors.blue600, backgroundColor: colors.blue50 },
});
