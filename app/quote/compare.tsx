import React, { useMemo, useState } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { CircleAlert, CloudOff, Info } from "lucide-react-native";
import { Banner, BrandHeader } from "@/components/design";
import { Screen } from "@/components/ui";
import { SortFilter } from "@/components/filters";
import { EmptyState, LoadingState } from "@/components/StatePanel";
import { ErrorCard, QuoteSteps } from "@/components/purchase/PurchaseUi";
import { ChooseButton, useNow } from "@/components/offers/OfferCard";
import { QuoteComparisonView } from "@/components/offers/QuoteComparisonView";
import { QuoteSummaryCard } from "@/components/offers/QuoteSummaryCard";
import { QuoteWorkflowApi } from "@/api/workflow";
import { useInsurance } from "@/store/insurance";
import { useChooseOffer, useEditQuote, useOpenQuote } from "@/hooks/useQuoteFlow";
import { useLoad } from "@/hooks/useLoad";
import { useFormatters } from "@/hooks/useFormatters";
import { liveOffers, OfferSort, sortOffers } from "@/lib/purchase";
import { compareAllIds, MAX_COMPARE, offerChoice, parseIds, type OfferChoice } from "@/lib/offerChoice";
import { comparisonFromOffers, coverStartOf } from "@/lib/offerComparison";
import { quoteOutcome } from "@/lib/quoteWorkflow";
import { bestValueOfferId } from "@/lib/renewal";
import { useTranslation } from "@/i18n";

/**
 * The single offer comparison (REQ-DST-003), opened with `quoteId` and the ticked `ids` (without
 * ids: the three cheapest offers that can still be chosen). Built by the server (POST
 * quote-comparisons with offer_ids) so every client shows the same normalized rows; when that call
 * fails the same table is built from the rated offers on the device. Quote summary with "Edit
 * quote", the sort in the one filter sheet (it orders the columns), then the table with one choose
 * button under each offer column. quote-comparison/[id] redirects here for customers.
 */
export default function CompareOffers() {
  const { t } = useTranslation();
  const f = useFormatters();
  const { quoteId, ids = "" } = useLocalSearchParams<{ quoteId?: string; ids?: string }>();
  const open = useOpenQuote(quoteId);
  const { quote, offers } = open;
  const product = useInsurance((s) => s.product);
  const busy = useInsurance((s) => s.busy);
  const now = useNow();
  const editQuote = useEditQuote(quote?.id);
  const pick = useChooseOffer(quote?.id, offers);
  const [sort, setSort] = useState<OfferSort>("price");

  const wanted = useMemo(() => {
    const ticked = parseIds(ids);
    return (ticked.length ? ticked : compareAllIds(offers, Date.now())).slice(0, MAX_COMPARE);
  }, [ids, offers]);
  const key = wanted.join(",");
  const selected = useMemo(() => offers.filter((o) => wanted.includes(o.id)), [offers, wanted]);
  const order = useMemo(() => sortOffers(selected, sort).map((o) => o.id), [selected, sort]);
  const cmp = useLoad(async () => {
    if (!quote || wanted.length < 2) return null;
    try {
      return { comparison: await QuoteWorkflowApi.compare(quote.id, wanted), local: false };
    } catch (e) {
      // Offline or refused (e.g. an offer superseded since): the same table from the offers on the device.
      if (selected.length >= 2) return { comparison: comparisonFromOffers(quote.id, selected, f.language), local: true };
      throw e;
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [quote?.id, key]);

  const outcome = quote ? quoteOutcome(quote, now) : null;
  const live = useMemo(() => liveOffers(selected, now), [selected, now]);
  const lowest = live.length ? live.reduce((a, b) => (b.total_minor < a.total_minor ? b : a)).id : null;
  const best = useMemo(() => bestValueOfferId(live), [live]);
  const backToOffers = () => (quote ? router.replace({ pathname: "/quote/offers", params: { quoteId: quote.id } }) : router.replace("/quotes"));

  if (!quote)
    return (
      <Screen>
        <BrandHeader title={t("cmpPageTitle")} />
        <QuoteSteps current={2} />
        {open.loading ? (
          <LoadingState label={t("qwLoading")} />
        ) : open.error ? (
          <ErrorCard error={open.error} fallback={t("qwLoadFailed")} onRetry={() => void open.reload()} />
        ) : (
          <EmptyState title={t("ofNoQuote")} message={t("ofNoQuoteBody")} action={t("quotesTitle")} onPress={() => router.replace("/quotes")} />
        )}
      </Screen>
    );

  const data = cmp.data;
  const tooFew = wanted.length < 2 || (!cmp.loading && !cmp.error && (!data || (data.comparison.offers?.length ?? 0) < 2));

  return (
    <Screen>
      <BrandHeader title={t("cmpPageTitle")} subtitle={t("cmpPageSubtitle")} />
      <QuoteSteps current={2} />
      <QuoteSummaryCard quote={quote} product={product} onEdit={pick.acceptedId || (outcome && outcome !== "EXPIRED") ? undefined : editQuote} />
      {tooFew ? (
        <EmptyState title={t("qtSelectTwoThree")} message={t("qtTickCompare")} action={t("qtBackToOffers")} onPress={backToOffers} />
      ) : cmp.loading && !data ? (
        <LoadingState label={t("qwCompareLoading")} />
      ) : cmp.error && !data ? (
        <ErrorCard error={cmp.error} fallback={t("qwCompareFailed")} onRetry={() => void cmp.reload()} />
      ) : data ? (
        <>
          <SortFilter
            value={sort}
            onChange={setSort}
            count={wanted.length}
            options={[
              { value: "price", label: t("ofSortPrice") },
              { value: "cover", label: t("ofSortCover") },
              { value: "insurer", label: t("ofSortInsurer") },
              { value: "excess", label: t("ofSortExcess") },
            ]}
          />
          {data.local ? <Banner icon={CloudOff} tint="gold" body={t("cmpLocalNote")} /> : null}
          {data.comparison.is_expired && !data.local ? <Banner icon={CircleAlert} tint="red" body={t("qwCompareExpired")} /> : null}
          {pick.error ? <ErrorCard error={pick.error} fallback={t("ofSelectFailed")} /> : null}
          <QuoteComparisonView
            comparison={data.comparison}
            offers={offers}
            order={order}
            now={now}
            coverStart={coverStartOf(quote.risk_facts)}
            subtitle={t("qtCompareSubtitle", { count: data.comparison.offers?.length ?? 0 })}
            status={(c) =>
              c.block === "accepted"
                ? { label: t("roSelected"), tone: "success" }
                : c.block === "expired"
                  ? { label: t("offerExpired"), tone: "danger" }
                  : c.block
                    ? { label: t("ofOfferUnavailable"), tone: "neutral" }
                    : c.offerId === lowest
                      ? { label: t("ofLowest"), tone: "success" }
                      : null
            }
            choose={(c) => {
              const offer = offers.find((o) => o.id === c.offerId);
              const choice: OfferChoice = offer ? offerChoice(offer, { acceptedId: pick.acceptedId, outcome, now }) : { kind: "blocked", reason: c.block ?? "unavailable" };
              return (
                <ChooseButton
                  compact
                  choice={choice}
                  best={choice.kind === "select" && best === c.offerId}
                  selecting={pick.choosing === c.offerId}
                  opening={pick.opening}
                  disabled={!!pick.choosing || busy}
                  onSelect={() => offer && void pick.choose(offer)}
                  onOpenApplication={() => void pick.openApplication()}
                />
              );
            }}
          />
        </>
      ) : null}
      <Banner icon={Info} tint="blue" body={t("qtCompareNote")} />
    </Screen>
  );
}
