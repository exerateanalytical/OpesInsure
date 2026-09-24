import React, { useMemo, useState } from "react";
import { ScrollView, StyleSheet, Text, useWindowDimensions, View } from "react-native";
import { router } from "expo-router";
import { Columns3, Info, RefreshCcw, SlidersHorizontal } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { EmptyState } from "@/components/StatePanel";
import { ErrorCard, Pill, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { OfferCard, useNow } from "@/components/offers/OfferCard";
import { useInsurance } from "@/store/insurance";
import { filterOffers, OfferSort, providerName, sortOffers, validityLeft } from "@/lib/purchase";
import { colors, space, type } from "@/theme/tokens";

const MAX_COMPARE = 3;
const toMinor = (v: string) => {
  const n = Number(v.replace(/[^\d]/g, ""));
  return v.trim() && Number.isFinite(n) ? n * 100 : null;
};

export default function Offers() {
  const offers = useInsurance((s) => s.offers);
  const quote = useInsurance((s) => s.quote);
  const busy = useInsurance((s) => s.busy);
  const error = useInsurance((s) => s.error);
  const choose = useInsurance((s) => s.selectOffer);
  const rerate = useInsurance((s) => s.rerateQuote);
  const { width } = useWindowDimensions();
  const now = useNow();
  const [sort, setSort] = useState<OfferSort>("price");
  const [providers, setProviders] = useState<string[]>([]);
  const [maxPremium, setMaxPremium] = useState("");
  const [maxExcess, setMaxExcess] = useState("");
  const [showFilters, setShowFilters] = useState(false);
  const [compare, setCompare] = useState<string[]>([]);
  const [selecting, setSelecting] = useState<string | null>(null);
  const [selectError, setSelectError] = useState<unknown>(null);

  const carriers = useMemo(() => {
    const seen = new Map<string, string>();
    offers.forEach((o) => seen.set(o.carrier_id, providerName(o)));
    return [...seen.entries()];
  }, [offers]);
  const visible = useMemo(
    () => sortOffers(filterOffers(offers, { providers, maxPremiumMinor: toMinor(maxPremium), maxExcessMinor: toMinor(maxExcess) }), sort),
    [offers, providers, maxPremium, maxExcess, sort],
  );
  const cheapest = useMemo(() => (offers.length ? Math.min(...offers.map((o) => o.total_minor)) : null), [offers]);
  const quoteExpired =
    String(quote?.status ?? "").toUpperCase() === "EXPIRED" ||
    (offers.length > 0 && offers.every((o) => validityLeft(o.valid_until, now).expired));
  const narrow = width < 600;
  const cardWidth = Math.min(width - 56, 420);

  const select = async (id: string) => {
    const offer = offers.find((o) => o.id === id);
    if (!offer || !quote || selecting) return;
    setSelecting(id);
    setSelectError(null);
    try {
      await choose(offer);
      const proposal = useInsurance.getState().proposal;
      if (proposal) router.push({ pathname: "/proposals/[id]", params: { id: proposal.id } });
    } catch (e) {
      setSelectError(e);
    } finally {
      setSelecting(null);
    }
  };
  const toggleCompare = (id: string) =>
    setCompare((c) => (c.includes(id) ? c.filter((x) => x !== id) : c.length >= MAX_COMPARE ? c : [...c, id]));

  if (!quote)
    return (
      <Screen>
        <AppHeader title="Available offers" back />
        <EmptyState title="No quote open" message="Start a new quote or resume a saved one to see offers." action="Saved quotes" onPress={() => router.replace("/quotes")} />
      </Screen>
    );

  const cards = visible.map((o) => (
    <OfferCard
      key={o.id}
      offer={o}
      now={now}
      width={narrow ? cardWidth : undefined}
      badge={o.total_minor === cheapest ? { label: "Lowest total", tone: "success" } : undefined}
      compareSelected={compare.includes(o.id)}
      onToggleCompare={offers.length > 1 ? () => toggleCompare(o.id) : undefined}
      selecting={selecting === o.id}
      disabled={!!selecting || busy || quoteExpired}
      onSelect={() => void select(o.id)}
    />
  ));

  return (
    <Screen>
      <AppHeader title="Available offers" subtitle={`Step 3 of 5 · ${offers.length} live carrier result${offers.length === 1 ? "" : "s"}`} back />
      <View style={st.notice}>
        <Info size={17} color={colors.blue700} />
        <Text style={st.noticeText}>Compare cover, excess and exclusions — not price alone.</Text>
      </View>
      {quoteExpired ? (
        <Card>
          <Text style={ps.title}>These prices have expired</Text>
          <Text style={ps.body}>Offers are only valid for a limited time. Re-rate to get current prices from the insurers.</Text>
          <Button label="Re-rate quote" icon={RefreshCcw} loading={busy} onPress={() => void rerate(quote.id).catch(() => undefined)} />
        </Card>
      ) : null}
      {error && !selectError ? <ErrorCard error={{ message: error }} fallback="Offers could not be updated." onRetry={() => void rerate(quote.id).catch(() => undefined)} retryLabel="Retry" /> : null}
      {selectError ? <ErrorCard error={selectError} fallback="This offer could not be selected." /> : null}
      {offers.length > 1 ? (
        <>
          <View style={ps.row}>
            <Text style={ps.meta}>Sort</Text>
            <Pill label="Lowest price" selected={sort === "price"} onPress={() => setSort("price")} />
            <Pill label="Most cover" selected={sort === "cover"} onPress={() => setSort("cover")} />
            <Pill label={showFilters ? "Hide filters" : "Filters"} selected={showFilters || providers.length > 0 || !!maxPremium || !!maxExcess} onPress={() => setShowFilters(!showFilters)} />
          </View>
          {showFilters ? (
            <Card>
              <View style={ps.row}>
                <SlidersHorizontal size={16} color={colors.neutral600} />
                <Text style={ps.title}>Filter offers</Text>
              </View>
              <Text style={ps.meta}>Insurer</Text>
              <View style={ps.row}>
                {carriers.map(([id, name]) => (
                  <Pill key={id} label={name} selected={providers.includes(id)} onPress={() => setProviders((p) => (p.includes(id) ? p.filter((x) => x !== id) : [...p, id]))} />
                ))}
              </View>
              <TextField label="Maximum total premium (FCFA)" value={maxPremium} onChangeText={setMaxPremium} keyboardType="numeric" placeholder="No limit" />
              <TextField label="Maximum excess (FCFA)" value={maxExcess} onChangeText={setMaxExcess} keyboardType="numeric" placeholder="No limit" />
              <Button label="Clear filters" variant="tertiary" onPress={() => { setProviders([]); setMaxPremium(""); setMaxExcess(""); }} />
            </Card>
          ) : null}
        </>
      ) : null}
      {offers.length === 0 ? (
        <Card>
          <Text style={ps.title}>No eligible offer was returned.</Text>
          <Text style={ps.meta}>Change the risk details or contact support. No substitute price will be shown.</Text>
          <Button label="Change details" variant="secondary" onPress={() => router.replace("/quote/risk")} />
          <Button label="Retry rating" variant="tertiary" loading={busy} onPress={() => void rerate(quote.id).catch(() => undefined)} />
        </Card>
      ) : visible.length === 0 ? (
        <EmptyState title="No offer matches these filters" message="Loosen the premium or excess limit, or include more insurers." action="Clear filters" onPress={() => { setProviders([]); setMaxPremium(""); setMaxExcess(""); }} />
      ) : narrow ? (
        <ScrollView horizontal snapToInterval={cardWidth + space.x3} decelerationRate="fast" snapToAlignment="start" showsHorizontalScrollIndicator={false} contentContainerStyle={st.carousel}>
          {cards}
        </ScrollView>
      ) : (
        cards
      )}
      {narrow && visible.length > 1 ? <Text style={[ps.meta, st.center]}>Swipe to see {visible.length} offers</Text> : null}
      {compare.length > 0 ? (
        <Button
          label={compare.length < 2 ? "Select at least 2 offers to compare" : `Compare ${compare.length} offers side by side`}
          icon={Columns3}
          disabled={compare.length < 2}
          onPress={() => router.push({ pathname: "/quote/compare", params: { ids: compare.join(",") } })}
        />
      ) : null}
    </Screen>
  );
}

const st = StyleSheet.create({
  notice: { flexDirection: "row", gap: space.x2, backgroundColor: colors.blue50, padding: space.x3, borderRadius: 10 },
  noticeText: { ...type.meta, color: colors.blue700, flex: 1 },
  carousel: { gap: space.x3, paddingRight: space.x5 },
  center: { textAlign: "center" },
});
