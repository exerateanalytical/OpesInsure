import React, { useState } from "react";
import { Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { Send } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { EmptyState, LoadingState } from "@/components/StatePanel";
import { ErrorCard, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { ProposalLifecycleApi } from "@/api/workflow";
import { useLoad } from "@/hooks/useLoad";
import { useTranslation } from "@/i18n";
import { localized, humanize } from "@/lib/purchase";
import { canResubmitProposal, requiredDocumentInfo } from "@/lib/quoteWorkflow";

/** Answer an underwriter's information request (GET proposals/{p}/checklist), then POST resubmit. */
export default function ProposalInformationRequest() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td, language } = useTranslation();
  const q = useLoad(() => ProposalLifecycleApi.checklist(id), [id]);
  const [response, setResponse] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const c = q.data;
  const request = c?.information_request;
  const allowed = c ? canResubmitProposal(c.status, c.available_transitions, c.blocking) : false;
  const docsBlocking = (c?.blocking ?? []).some((b) => b.startsWith("DOCUMENT_"));

  const resubmit = async () => {
    setBusy(true);
    setError(null);
    try {
      await ProposalLifecycleApi.resubmit(id, response);
      router.replace({ pathname: "/proposals/[id]", params: { id } });
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen>
      <AppHeader title={t("prInfoTitle")} back />
      {q.loading && !c ? <LoadingState label={t("prInfoLoading")} /> : null}
      {q.error && !c ? <ErrorCard error={q.error} fallback={t("prLoadFailed")} onRetry={() => void q.reload()} /> : null}
      {c && String(c.status).toUpperCase() !== "INFORMATION_REQUIRED" ? (
        <EmptyState title={t("prInfoNone")} message={td(`proposalMsg_${String(c.status).toUpperCase()}`, c.status)} action={t("prAll")} onPress={() => router.replace({ pathname: "/proposals/[id]", params: { id } })} />
      ) : null}
      {c && String(c.status).toUpperCase() === "INFORMATION_REQUIRED" ? (
        <>
          <Card feature>
            <Text style={ps.title}>{t("prInfoItems")}</Text>
            {request?.message ? <Text style={ps.body}>{request.message}</Text> : null}
            {(request?.items ?? []).map((it, i) => (
              <Text key={it.code ?? i} style={ps.body}>• {it.description}</Text>
            ))}
          </Card>
          {c.required_documents.length ? (
            <Card>
              <Text style={ps.title}>{t("prInfoDocs")}</Text>
              {c.required_documents.map((d) => {
                const info = requiredDocumentInfo(d.status);
                return (
                  <View key={d.code} style={ps.between}>
                    <Text style={[ps.body, { flex: 1 }]}>{d.label ?? (localized(d.name, language) || humanize(d.code))}</Text>
                    <StatusChip label={td(info.key, d.status ?? "")} tone={info.tone} />
                  </View>
                );
              })}
              {docsBlocking ? (
                <>
                  <Text style={ps.error}>{t("prInfoBlocked")}</Text>
                  <Button label={t("prUpload")} variant="secondary" onPress={() => router.replace({ pathname: "/proposals/[id]", params: { id } })} />
                </>
              ) : null}
            </Card>
          ) : null}
          <TextField label={t("prInfoResponse")} multiline maxLength={4000} value={response} onChangeText={setResponse} />
          {error ? <ErrorCard error={error} fallback={t("prInfoFailed")} /> : null}
          <Button label={t("prInfoResubmit")} icon={Send} loading={busy} disabled={!allowed || busy} onPress={() => void resubmit()} />
        </>
      ) : null}
    </Screen>
  );
}
