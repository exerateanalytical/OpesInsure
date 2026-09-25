import React from "react";
import { router, useLocalSearchParams } from "expo-router";
import { Camera } from "lucide-react-native";
import { AppHeader, Button, Card, Screen } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { StatePanel } from "@/components/StatePanel";
import { ClaimsCompletionApi } from "@/api/client";
import { useLoad } from "@/hooks/useLoad";
import { useTranslation } from "@/i18n";

export default function Checklist() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td } = useTranslation();
  const { data, loading, error, reload } = useLoad(() => ClaimsCompletionApi.evidenceRequirements(id), [id]);
  return (
    <Screen>
      <AppHeader title={t("checklistTitle")} subtitle={t("checklistSubtitle")} back />
      <StatePanel
        loading={loading}
        error={error}
        data={data}
        onRetry={() => void reload()}
        emptyTitle={t("checklistEmpty")}
        emptyMessage={t("checklistEmptyBody")}
      >
        {(items) => (
          <Card>
            {items.map((e) => (
              <FlowRow
                key={e.key}
                icon={Camera}
                title={e.required ? t("checklistRequired", { label: e.label }) : e.label}
                subtitle={e.guidance}
                status={td(`status_${e.status}`, e.status)}
                onPress={() => router.push(`/claim/${id}/evidence`)}
              />
            ))}
          </Card>
        )}
      </StatePanel>
      <Button label={t("checklistOpenInspection")} onPress={() => router.push(`/claim/${id}/inspection`)} />
    </Screen>
  );
}
