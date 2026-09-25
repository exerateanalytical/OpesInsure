import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { CloudUpload } from "lucide-react-native";
import { AppHeader, Button, Card, Screen } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { AgentApi } from "@/api/client";
import { useTranslation } from "@/i18n";
export default function AgentOffline() {
  const { t } = useTranslation();
  const q = useLoad(() => AgentApi.offlineQueue(), []);
  const x = q.data ?? [];
  const setX = q.setData;
  return (
    <Screen>
      <AppHeader
        title={t("agOfflineFieldActivity")}
        subtitle={t("agNothingSubmitted")}
        back
      />
      <StatePanel {...q} onRetry={q.reload}>
        {() => (
          <>
          {x.map((i) => (
            <Card key={i.id}>
              <FlowRow
                icon={CloudUpload}
                title={i.type.replaceAll("_", " ")}
                subtitle={`${i.local_reference} · ${i.error ?? i.updated_at}`}
                status={i.status}
              />
              {i.status === "FAILED" ? (
                <Button
                  label={t("agRetrySecureSync")}
                  variant="secondary"
                  onPress={async () => {
                    const v = await AgentApi.retryOffline(i.id);
                    setX(x.map((a) => (a.id === v.id ? v : a)));
                  }}
                />
              ) : null}
            </Card>
          ))}
          </>
        )}
      </StatePanel>
    </Screen>
  );
}
