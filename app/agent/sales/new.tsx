import React, { useEffect, useState } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { Pressable, StyleSheet, Text } from "react-native";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { AgentApi, AgentClient } from "@/api/client";
import { colors, radius, space, type } from "@/theme/tokens";
const products = [
  "Motor Third Party",
  "Motor Comprehensive",
  "Travel",
  "Health",
];
export default function AgentSaleNew() {
  const { customerId } = useLocalSearchParams<{ customerId?: string }>();
  const [clients, setClients] = useState<AgentClient[]>([]);
  const [client, setClient] = useState(customerId ?? "");
  const [product, setProduct] = useState(products[0]!);
  const [phone, setPhone] = useState("+237");
  useEffect(() => {
    AgentApi.clients().then((x) => {
      setClients(x);
      if (customerId) {
        const c = x.find((v) => v.id === customerId);
        if (c) setPhone(c.phone_e164);
      }
    });
  }, [customerId]);
  return (
    <Screen>
      <AppHeader
        title="Assisted insurance sale"
        subtitle="The client authorizes payment on their own phone"
        back
      />
      <Card>
        <Text style={s.label}>Client</Text>
        {clients.map((c) => (
          <Pressable
            key={c.id}
            style={[s.option, client === c.id && s.selected]}
            onPress={() => {
              setClient(c.id);
              setPhone(c.phone_e164);
            }}
          >
            <Text>
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
            <Text>{p}</Text>
          </Pressable>
        ))}
        <TextField
          label="Client payment phone"
          keyboardType="phone-pad"
          value={phone}
          onChangeText={setPhone}
        />
        <Text>
          The agent must never collect or enter the client’s Mobile Money PIN.
        </Text>
        <Button
          label="Create quote and review"
          disabled={!client || phone.length < 8}
          onPress={async () => {
            const x = await AgentApi.createSale({
              customer_id: client,
              product,
              payment_phone_e164: phone,
            });
            router.replace(`/agent/sales/${x.id}`);
          }}
        />
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
  selected: { borderColor: colors.blue600, backgroundColor: colors.blue50 },
});
