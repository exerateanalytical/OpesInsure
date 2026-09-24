import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { router, useLocalSearchParams } from "expo-router";
import { Text } from "react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { AgentApi } from "@/api/client";
export default function AgentClientDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useLoad(() => AgentApi.client(id), [id]);
  const x = q.data;
  return (
    <Screen>
      <AppHeader title={x?.full_name ?? "Client"} back />
      <StatePanel {...q} onRetry={q.reload}>
        {() => (
          <>
          <Card>
            <StatusChip label={x?.kyc_status ?? "LOADING"} tone="info" />
            <Text>{x?.phone_e164}</Text>
            <Text>{x?.city}</Text>
            <Text>
              {x?.origin_locked
                ? "Origin-protected client"
                : "Ownership awaiting server confirmation"}
            </Text>
            <Text>Active policies: {x?.active_policies ?? 0}</Text>
            <Text>Renewal due: {x?.renewal_due_at ?? "None"}</Text>
          </Card>
          <Button
            label="Start assisted sale"
            onPress={() => router.push(`/agent/sales/new?customerId=${id}`)}
          />
          </>
        )}
      </StatePanel>
    </Screen>
  );
}
