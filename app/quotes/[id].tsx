import React, { useEffect, useState } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { Alert, Text } from "react-native";
import {
  AppHeader,
  Button,
  Card,
  Money,
  Screen,
  StatusChip,
} from "@/components/ui";
import { CustomerQuoteSummary, QuotesApi } from "@/api/client";
export default function QuoteDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [q, setQ] = useState<CustomerQuoteSummary>();
  useEffect(() => {
    QuotesApi.show(id).then((x) => setQ(x.summary));
  }, [id]);
  return (
    <Screen>
      <AppHeader title={q?.product_name ?? "Quote"} back />
      <Card feature>
        <StatusChip
          label={q?.status ?? "LOADING"}
          tone={q?.can_resume ? "success" : "warning"}
        />
        <Text>{q?.vehicle_label}</Text>
        {q?.lowest_total_minor ? (
          <Money amount={q.lowest_total_minor / 100} size="large" />
        ) : null}
        <Text>
          {q?.offer_count ?? 0} insurer offers · Valid until{" "}
          {q?.expires_at?.slice(0, 10)}
        </Text>
      </Card>
      {q?.can_resume ? (
        <Button
          label="Resume comparison"
          onPress={async () => {
            await QuotesApi.resume(id);
            router.push("/quote/offers");
          }}
        />
      ) : null}
      <Button
        label="Remove saved quote"
        variant="danger"
        onPress={() =>
          Alert.alert(
            "Remove quote?",
            "This removes the saved quote from this device and your account.",
            [
              { text: "Cancel", style: "cancel" },
              {
                text: "Remove",
                style: "destructive",
                onPress: async () => {
                  await QuotesApi.discard(id);
                  router.back();
                },
              },
            ],
          )
        }
      />
    </Screen>
  );
}
