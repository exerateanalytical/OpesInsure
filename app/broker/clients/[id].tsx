import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { useLocalSearchParams } from "expo-router";
import { Text } from "react-native";
import { AppHeader, Card, Money, Screen, StatusChip } from "@/components/ui";
import { BrokerApi } from "@/api/client";
export default function BrokerClientDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useLoad(() => BrokerApi.client(id), [id]);
  const x = q.data;
  return (
    <Screen>
      <AppHeader title={x?.full_name ?? "Client"} back />
      <StatePanel {...q} onRetry={q.reload}>
        {() => (
          <>
          <Card>
            <StatusChip
              label={x?.origin_locked ? "BROKER ORIGIN LOCKED" : "REVIEW"}
              tone="success"
            />
            <Text>
              {x?.phone_e164} · {x?.city}
            </Text>
            <Text>Policies: {x?.policies}</Text>
            <Text>Outstanding balance</Text>
            {x ? <Money amount={x.outstanding_minor / 100} size="large" /> : null}
            <Text>Next renewal: {x?.renewal_due_at ?? "None"}</Text>
          </Card>
          </>
        )}
      </StatePanel>
    </Screen>
  );
}
