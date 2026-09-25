import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { Store } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { BrokerApi } from "@/api/client";
import { Text } from "react-native";
import { useTranslation } from "@/i18n";
export default function Publications() {
  const { t } = useTranslation();
  const q = useLoad(() => BrokerApi.publications(), []);
  const x = q.data ?? [];
  const setX = q.setData;
  return (
    <Screen>
      <AppHeader
        title={t("brMarketplacePublications")}
        subtitle={t("brPublicationApproval")}
        back
      />
      <StatePanel {...q} onRetry={q.reload}>
        {() => (
          <>
          {x.map((p) => (
            <Card key={p.id}>
              <Store size={22} />
              <Text>{p.product_name}</Text>
              <StatusChip
                label={p.status}
                tone={p.status === "PUBLISHED" ? "success" : "warning"}
              />
              <Text>
                {p.channel} · submitted {p.submitted_at}
              </Text>
              <Button
                label={
                  p.status === "PUBLISHED" ? t("brUnpublish") : t("brSubmitPublication")
                }
                variant="secondary"
                onPress={async () => {
                  const v = await BrokerApi.togglePublication(
                    p.id,
                    p.status !== "PUBLISHED",
                  );
                  setX(x.map((a) => (a.id === v.id ? v : a)));
                }}
              />
            </Card>
          ))}
          </>
        )}
      </StatePanel>
    </Screen>
  );
}
