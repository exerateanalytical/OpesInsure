import React, { useEffect, useState } from "react";
import { router } from "expo-router";
import { Clock3 } from "lucide-react-native";
import { AppHeader, Card, Screen } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { CustomerQuoteSummary, QuotesApi } from "@/api/client";
export default function QuoteHistory() {
  const [x, setX] = useState<CustomerQuoteSummary[]>([]);
  useEffect(() => {
    QuotesApi.history().then(setX);
  }, []);
  return (
    <Screen>
      <AppHeader
        title="Saved quotes"
        subtitle="Resume valid quotes or review previous comparisons"
        back
      />
      <Card>
        {x.map((q) => (
          <FlowRow
            key={q.id}
            icon={Clock3}
            title={q.product_name ?? q.line_code}
            subtitle={`${q.vehicle_label ?? ""} · ${q.offer_count} offers`}
            status={q.status}
            onPress={() => router.push(`/quotes/${q.id}`)}
          />
        ))}
      </Card>
    </Screen>
  );
}
