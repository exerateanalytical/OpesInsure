import React, { useState } from "react";
import { Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { FileText, MessageSquareWarning, Send } from "lucide-react-native";
import { Banner, BrandHeader, CtaBar, DetailRow, SectionHeading } from "@/components/design";
import { Button, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { EmptyState, LoadingState } from "@/components/StatePanel";
import { ErrorCard, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { ProposalLifecycleApi } from "@/api/workflow";
import { useLoad } from "@/hooks/useLoad";
import { useTranslation } from "@/i18n";
import { localized, humanize } from "@/lib/purchase";
import { canResubmitProposal, requiredDocumentInfo } from "@/lib/quoteWorkflow";
import { ReviewFooter, ReviewIntro, ReviewRow, ReviewSection } from "@/components/review/ReviewSummary";

/** Answer an underwriter's information request (GET proposals/{p}/checklist), then POST resubmit. */
export default function ProposalInformationRequest() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td, language } = useTranslation();
  const q = useLoad(() => ProposalLifecycleApi.checklist(id), [id]);
  const [response, setResponse] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);
  // The answer and the documents on file are shown read-only before the application is resubmitted.
  const [reviewing, setReviewing] = useState(false);
  const c = q.data;
  const request = c?.information_request;
  const allowed = c ? canResubmitProposal(c.status, c.available_transitions, c.blocking) : false;
  const docsBlocking = (c?.blocking ?? []).some((b) => b.startsWith("DOCUMENT_"));
  const open = !!c && String(c.status).toUpperCase() === "INFORMATION_REQUIRED";

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
    <Screen
      footer={
        open && reviewing ? (
          <ReviewFooter label={t("prInfoResubmit")} icon={Send} loading={busy} disabled={!allowed} onConfirm={() => void resubmit()} onBack={() => setReviewing(false)} />
        ) : open ? (
          <CtaBar>
            <Button label={t("reviewContinue")} disabled={!allowed} onPress={() => { setError(null); setReviewing(true); }} />
          </CtaBar>
        ) : null
      }
    >
      <BrandHeader title={t("prInfoTitle")} />
      {q.loading && !c ? <LoadingState label={t("prInfoLoading")} /> : null}
      {q.error && !c ? <ErrorCard error={q.error} fallback={t("prLoadFailed")} onRetry={() => void q.reload()} /> : null}
      {c && !open ? (
        <EmptyState title={t("prInfoNone")} message={td(`proposalMsg_${String(c.status).toUpperCase()}`, c.status)} action={t("prAll")} onPress={() => router.replace({ pathname: "/proposals/[id]", params: { id } })} />
      ) : null}
      {c && open && reviewing ? (
        <>
          <ReviewIntro />
          <ReviewSection icon={MessageSquareWarning} title={t("prInfoTitle")} onEdit={() => setReviewing(false)}>
            <ReviewRow first label={t("prInfoResponse")} value={response.trim()} />
          </ReviewSection>
          {c.required_documents.length ? (
            <ReviewSection icon={FileText} title={t("prInfoDocs")} onEdit={() => setReviewing(false)}>
              {c.required_documents.map((d, i) => (
                <ReviewRow key={d.code} first={i === 0} label={d.label ?? (localized(d.name, language) || humanize(d.code))} value={td(requiredDocumentInfo(d.status).key, d.status ?? "")} />
              ))}
            </ReviewSection>
          ) : null}
          {error ? <ErrorCard error={error} fallback={t("prInfoFailed")} /> : null}
        </>
      ) : c && open ? (
        <>
          <Card>
            <SectionHeading icon={MessageSquareWarning} title={t("prInfoItems")} />
            {request?.message ? <Text style={ps.body}>{request.message}</Text> : null}
            {(request?.items ?? []).map((it, i) => (
              <Text key={it.code ?? i} style={ps.body}>• {it.description}</Text>
            ))}
          </Card>
          {c.required_documents.length ? (
            <Card>
              <SectionHeading icon={FileText} title={t("prInfoDocs")} />
              {c.required_documents.map((d) => {
                const info = requiredDocumentInfo(d.status);
                return (
                  <DetailRow key={d.code} label={d.label ?? (localized(d.name, language) || humanize(d.code))} valueNode={<StatusChip label={td(info.key, d.status ?? "")} tone={info.tone} />} />
                );
              })}
              {docsBlocking ? (
                <View style={{ gap: 8 }}>
                  <Banner icon={FileText} tint="red" body={t("prInfoBlocked")} />
                  <Button label={t("prUpload")} variant="secondary" onPress={() => router.replace({ pathname: "/proposals/[id]", params: { id } })} />
                </View>
              ) : null}
            </Card>
          ) : null}
          <Card>
            <TextField label={t("prInfoResponse")} multiline maxLength={4000} value={response} onChangeText={setResponse} />
          </Card>
          {error ? <ErrorCard error={error} fallback={t("prInfoFailed")} /> : null}
        </>
      ) : null}
    </Screen>
  );
}
