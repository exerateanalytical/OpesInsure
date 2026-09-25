import React, { useState } from "react";
import { Linking, Platform, Share, Text, View } from "react-native";
import { router } from "expo-router";
import { Columns3, FileDown, Send, XCircle } from "lucide-react-native";
import { Button, Card, StatusChip, TextField } from "@/components/ui";
import { ErrorCard, InfoRow, PickerField, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { QuoteWorkflowApi, WorkflowQuote } from "@/api/workflow";
import { useLoad } from "@/hooks/useLoad";
import { useFormatters } from "@/hooks/useFormatters";
import { useTranslation } from "@/i18n";
import { colors } from "@/theme/tokens";
import { canDeclineQuote, hasQuoteDocument, QUOTE_DECLINE_REASONS, quoteOutcome, quoteStateKey, quoteTone, sentToInsurer } from "@/lib/quoteWorkflow";

/** Opens a PDF held as a data: URI (the endpoint needs the bearer token, so no plain link). */
async function openPdf(dataUri: string, title: string, unavailable: string) {
  try {
    await Linking.openURL(dataUri);
  } catch {
    if (Platform.OS === "ios") await Share.share({ url: dataUri, title });
    else throw new Error(unavailable);
  }
}

/** "Sent to insurer" summary from GET quotes/{id}/carrier-requests; renders nothing for auto-rated quotes. */
export function SentToInsurerCard({ quoteId }: { quoteId: string }) {
  const { t } = useTranslation();
  const f = useFormatters();
  const q = useLoad(() => QuoteWorkflowApi.carrierRequests(quoteId).catch(() => []), [quoteId]);
  const sent = sentToInsurer(q.data);
  if (!sent) return null;
  return (
    <Card>
      <View style={ps.row}>
        <Send size={18} color={colors.blue600} />
        <Text style={ps.title}>{t("qwSentTitle")}</Text>
      </View>
      {sent.waiting ? <Text style={ps.body}>{t("qwSentWaiting", { count: sent.waiting })}</Text> : <Text style={ps.body}>{t("qwSentAllAnswered")}</Text>}
      {sent.nextDueAt ? <Text style={ps.meta}>{t("qwSentDue", { date: f.dateTime(sent.nextDueAt) })}</Text> : null}
      {sent.offered ? <Text style={ps.meta}>{t("qwSentOffered", { count: sent.offered })}</Text> : null}
      {sent.declined ? <Text style={ps.meta}>{t("qwSentDeclined", { count: sent.declined })}</Text> : null}
    </Card>
  );
}

/**
 * Batch 6 quote workflow block shared by customer and partner quote screens: quote number + lifecycle
 * state, sent-to-insurer, PDF, comparison and decline. Reads the canonical GET quotes/{id}.
 */
export function QuoteWorkflowPanel({ quoteId, offerCount, onDeclined }: { quoteId: string; offerCount?: number; onDeclined?: (q: WorkflowQuote) => void }) {
  const { t, td } = useTranslation();
  const q = useLoad(() => QuoteWorkflowApi.show(quoteId), [quoteId]);
  const quote = q.data?.quote ?? null;
  const offers = offerCount ?? q.data?.offers?.length ?? 0;
  const [declining, setDeclining] = useState(false);
  const [reason, setReason] = useState<string | undefined>(undefined);
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState<"pdf" | "decline" | null>(null);
  const [error, setError] = useState<{ e: unknown; fallback: string } | null>(null);

  if (!quote) return q.error ? <ErrorCard error={q.error} fallback={t("qwLoadFailed")} onRetry={() => void q.reload()} /> : null;
  const outcome = quoteOutcome(quote);

  const pdf = async () => {
    setBusy("pdf");
    setError(null);
    try {
      const doc = await QuoteWorkflowApi.document(quoteId);
      await openPdf(doc.dataUri, quote.quote_number ?? t("qwDocument"), t("qwDocumentFailed"));
    } catch (e) {
      setError({ e, fallback: t("qwDocumentFailed") });
    } finally {
      setBusy(null);
    }
  };
  const decline = async () => {
    if (!reason) return;
    setBusy("decline");
    setError(null);
    try {
      const updated = await QuoteWorkflowApi.decline(quoteId, reason, note);
      q.setData({ quote: { ...quote, ...updated }, offers: q.data?.offers ?? [] });
      setDeclining(false);
      onDeclined?.(updated);
    } catch (e) {
      setError({ e, fallback: t("qwDeclineFailed") });
    } finally {
      setBusy(null);
    }
  };

  return (
    <>
      <Card>
        <View style={ps.between}>
          <Text style={ps.title}>{quote.quote_number ?? t("pqTitle")}</Text>
          <StatusChip label={td(quoteStateKey(quote), quote.lifecycle_state ?? quote.status)} tone={quoteTone(quote)} />
        </View>
        {quote.quote_number ? <InfoRow label={t("qwNumber")} value={quote.quote_number} /> : null}
        {quote.lifecycle_state ? <InfoRow label={t("qwStage")} value={td(`quoteLifecycle_${String(quote.lifecycle_state).toUpperCase()}`, quote.lifecycle_state)} /> : null}
        {outcome === "DECLINED" ? (
          <View style={{ gap: 8 }}>
            <View style={ps.row}>
              <XCircle size={18} color={colors.dangerText} />
              <Text style={ps.title}>{t("qwDeclinedTitle")}</Text>
            </View>
            {quote.decline_reason_code ? <Text style={ps.meta}>{td(`qwDeclineReason_${quote.decline_reason_code}`, quote.decline_reason_code)}</Text> : null}
            <Text style={ps.body}>{t("qwDeclinedBody")}</Text>
          </View>
        ) : null}
        {hasQuoteDocument(quote) ? (
          <Button label={t("qwDocumentOpen")} icon={FileDown} variant="secondary" loading={busy === "pdf"} disabled={!!busy} onPress={() => void pdf()} />
        ) : !outcome ? (
          <Text style={ps.meta}>{t("qwDocumentNotReady")}</Text>
        ) : null}
        {offers >= 2 && outcome !== "DECLINED" ? (
          <Button label={t("qwCompare")} icon={Columns3} variant="secondary" disabled={!!busy} onPress={() => router.push({ pathname: "/quote-comparison/[id]", params: { id: quoteId } })} />
        ) : null}
      </Card>
      <SentToInsurerCard quoteId={quoteId} />
      {canDeclineQuote(quote) ? (
        declining ? (
          <Card>
            <Text style={ps.title}>{t("qwDeclineTitle")}</Text>
            <PickerField
              label={t("qwDeclineTitle")}
              value={reason}
              options={QUOTE_DECLINE_REASONS.map((r) => ({ value: r, label: t(`qwDeclineReason_${r}`) }))}
              onChange={setReason}
            />
            <TextField label={t("qwDeclineNote")} value={note} onChangeText={setNote} multiline maxLength={1000} />
            <Button label={t("qwDeclineConfirm")} variant="danger" loading={busy === "decline"} disabled={!reason || !!busy} onPress={() => void decline()} />
            <Button label={t("cancel")} variant="tertiary" disabled={!!busy} onPress={() => setDeclining(false)} />
          </Card>
        ) : (
          <Button label={t("qwDecline")} variant="tertiary" disabled={!!busy} onPress={() => setDeclining(true)} />
        )
      ) : null}
      {error ? <ErrorCard error={error.e} fallback={error.fallback} /> : null}
    </>
  );
}
