import React, { useCallback } from "react";
import { router, useFocusEffect } from "expo-router";
import { CircleHelp, LifeBuoy, Plus } from "lucide-react-native";
import { AppHeader, Button, Card, Screen } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { EmptyState, ErrorState, LoadingState } from "@/components/StatePanel";
import { SupportApi } from "@/api/client";
import { rows } from "@/api/extra";
import { SupportContactList } from "@/components/auth/SupportContacts";
import { useLoad } from "@/hooks/useLoad";
import { useTranslation } from "@/i18n";

export default function Support() {
  const { t, td } = useTranslation();
  const q = useLoad(async () => rows(await SupportApi.list()));
  const reload = q.reload;
  useFocusEffect(
    useCallback(() => {
      void reload();
    }, [reload]),
  );
  return (
    <Screen>
      <AppHeader title={t("helpComplaints")} subtitle={t("supportSubtitle")} back />
      <Button label={t("supportNewTicket")} icon={Plus} onPress={() => router.push("/support/new")} />
      <Button label={t("faqTitle")} icon={CircleHelp} variant="secondary" onPress={() => router.push("/support/faq")} />
      <SupportContactList heading={t("talkToUs")} />
      {q.loading && !q.data ? (
        <LoadingState label={t("supportLoading")} />
      ) : q.error && !q.data ? (
        <ErrorState onRetry={() => void q.reload()} />
      ) : (q.data ?? []).length === 0 ? (
        <EmptyState title={t("supportEmpty")} message={t("supportEmptyBody")} />
      ) : (
        <Card>
          {(q.data ?? []).map((c) => (
            <FlowRow
              key={c.id}
              icon={LifeBuoy}
              title={c.subject}
              subtitle={`${c.reference} · ${td(`supportCategory_${c.category}`, c.category)}`}
              status={td(`supportStatus_${c.status}`, c.status)}
              onPress={() => router.push({ pathname: "/support/[id]", params: { id: c.id } })}
            />
          ))}
        </Card>
      )}
    </Screen>
  );
}
