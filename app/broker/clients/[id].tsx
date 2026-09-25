import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { useLocalSearchParams } from "expo-router";
import { Text } from "react-native";
import { AppHeader, Card, Money, Screen, StatusChip } from "@/components/ui";
import { BrokerApi } from "@/api/client";
import { useTranslation } from "@/i18n";
export default function BrokerClientDetail() {
  const { t } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useLoad(() => BrokerApi.client(id), [id]);
  const x = q.data;
  return (
    <Screen>
      <AppHeader title={x?.full_name ?? t("agClient")} back />
      <StatePanel {...q} onRetry={q.reload}>
        {() => (
          <>
          <Card>
            <StatusChip
              label={x?.origin_locked ? t("brOriginLockedBroker") : "REVIEW"}
              tone="success"
            />
            <Text>
              {[x?.phone_e164, x?.city].filter(Boolean).join(" · ")}
            </Text>
            <Text>Policies: {x?.policies}</Text>
            <Text>{t("brOutstandingBalance")}</Text>
            {x ? <Money amount={x.outstanding_minor / 100} size="large" /> : null}
            <Text>Next renewal: {x?.renewal_due_at ?? t("agNone")}</Text>
          </Card>
          </>
        )}
      </StatePanel>
    </Screen>
  );
}
