import React, { useState } from "react";
import { Alert, Text } from "react-native";
import { Href, router, useLocalSearchParams } from "expo-router";
import { RefreshCcw } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { LoadingState } from "@/components/StatePanel";
import { ErrorCard, InfoRow, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { QuoteResult, QuotesApi } from "@/api/client";
import { useInsurance } from "@/store/insurance";
import { useLoad } from "@/hooks/useLoad";
import { useFormatters } from "@/hooks/useFormatters";
import { humanize, localized } from "@/lib/purchase";
import { useTranslation } from "@/i18n";

/** Only in-app, customer-owned paths may come back from the server. */
const SAFE_NEXT = /^\/(quote|proposals|checkout|payment|confirmation|policy)(\/|$|\?)/;

export default function QuoteDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const f = useFormatters();
  const { t } = useTranslation();
  const setQuoteResult = useInsurance((s) => s.setQuoteResult);
  const loadQuote = useInsurance((s) => s.loadQuote);
  const rerate = useInsurance((s) => s.rerateQuote);
  const { data, loading, error, reload } = useLoad(() => QuotesApi.show(id), [id]);
  const [busy, setBusy] = useState<"resume" | "rerate" | "discard" | null>(null);
  const [actionError, setActionError] = useState<unknown>(null);

  const quote = data?.quote;
  const offers = data?.offers ?? [];
  const summary = data?.summary;
  const status = String(quote?.status ?? summary?.status ?? "").toUpperCase();
  const expired = status === "EXPIRED" || (!!quote?.expires_at && Date.parse(quote.expires_at) < Date.now());
  const referred = status === "REFERRED";
  const lowest = summary?.lowest_total_minor ?? (offers.length ? Math.min(...offers.map((o) => o.total_minor)) : null);
  const canResume = summary?.can_resume ?? (["SUBMITTED", "OFFERED", "REFERRED"].includes(status) && !expired);
  const name = summary?.product_name ?? (localized(offers[0]?.product?.name, f.language) || humanize(quote?.line_code));

  const run = async (kind: "resume" | "rerate", fn: () => Promise<void>) => {
    if (busy) return;
    setBusy(kind);
    setActionError(null);
    try {
      await fn();
    } catch (e) {
      setActionError(e);
    } finally {
      setBusy(null);
    }
  };

  // Load the quote + offers into the store BEFORE navigating (audit #12).
  const resume = () =>
    run("resume", async () => {
      const r = await QuotesApi.resume(id);
      let result: QuoteResult;
      if (r.quote) {
        result = { quote: r.quote, offers: r.offers ?? [] };
        setQuoteResult(result.quote, result.offers);
      } else result = await loadQuote(r.quote_id ?? id);
      if (r.next_path && SAFE_NEXT.test(r.next_path)) return router.push(r.next_path as Href);
      if (String(result.quote.status).toUpperCase() === "REFERRED") return router.push({ pathname: "/quote/referral", params: { quoteId: id } });
      router.push("/quote/offers");
    });
  const reRate = () =>
    run("rerate", async () => {
      await rerate(id);
      router.push("/quote/offers");
    });

  return (
    <Screen>
      <AppHeader title={name || "Quote"} back />
      {loading && !data ? <LoadingState label="Loading quote…" /> : null}
      {error && !data ? <ErrorCard error={error} fallback="This quote could not be loaded." onRetry={() => void reload()} /> : null}
      {data ? (
        <>
          <Card feature>
            <StatusChip label={expired ? "Expired" : humanize(status)} tone={expired ? "danger" : referred ? "warning" : canResume ? "success" : "neutral"} />
            {summary?.vehicle_label ? <Text style={ps.body}>{summary.vehicle_label}</Text> : null}
            {lowest !== null ? <Text style={ps.title}>From {f.xaf(lowest)}</Text> : null}
            <InfoRow label="Insurer offers" value={String(summary?.offer_count ?? offers.length)} />
            <InfoRow label={expired ? "Expired" : "Valid until"} value={f.dateTime(quote?.expires_at ?? summary?.expires_at)} />
          </Card>
          {referred ? (
            <Card>
              <Text style={ps.title}>Manual underwriting</Text>
              <Text style={ps.body}>An underwriter must review this request before prices are confirmed.</Text>
              <Button label="View underwriting status" variant="secondary" onPress={() => router.push({ pathname: "/quote/referral", params: { quoteId: id } })} />
            </Card>
          ) : null}
          {actionError ? <ErrorCard error={actionError} fallback="That action failed. Try again." /> : null}
          {expired ? (
            <Card>
              <Text style={ps.body}>Prices expire to reflect current tariffs. Re-rate to get fresh offers with the same details.</Text>
              <Button label="Re-rate this quote" icon={RefreshCcw} loading={busy === "rerate"} disabled={!!busy} onPress={() => void reRate()} />
            </Card>
          ) : canResume && !referred ? (
            <Button label="Resume comparison" loading={busy === "resume"} disabled={!!busy} onPress={() => void resume()} />
          ) : null}
          <Button
            label="Remove saved quote"
            variant="danger"
            disabled={!!busy}
            onPress={() =>
              Alert.alert(t("qtRemoveQ"), t("qtRemoveBody"), [
                { text: t("cancel"), style: "cancel" },
                {
                  text: t("qtRemove"),
                  style: "destructive",
                  onPress: async () => {
                    setBusy("discard");
                    try {
                      await QuotesApi.discard(id);
                      router.back();
                    } catch (e) {
                      setActionError(e);
                    } finally {
                      setBusy(null);
                    }
                  },
                },
              ])
            }
          />
        </>
      ) : null}
    </Screen>
  );
}
