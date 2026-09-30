import React, { useMemo } from "react";
import { Pressable, ScrollView, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { ArrowRight, BarChart3, ChevronRight, FileText, HeartHandshake, LucideIcon, MapPin, ShieldCheck, Users, Wrench } from "lucide-react-native";
import { Button, Screen, ripple } from "@/components/ui";
import { BrandHeader, CheckList, CtaBar, IconTile, SectionHeading } from "@/components/design";
import { InstitutionMark, institutionLogo } from "@/components/InstitutionMark";
import { EmptyState, ErrorState, LoadingState } from "@/components/StatePanel";
import { CATEGORIES } from "@/components/customer/categories";
import { api } from "@/api/client";
import { CustomerApi } from "@/api/customer";
import { useLoad } from "@/hooks/useLoad";
import { useInsurance } from "@/store/insurance";
import { localized } from "@/lib/purchase";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

/** GET /catalogue/lines (CatalogueController::lines): ACTIVE lines with their coverages and exclusions. */
type CatalogueLine = {
  code: string;
  name?: unknown;
  description?: unknown;
  coverages?: { code: string; name?: unknown; description?: unknown; mandatory?: boolean }[];
  exclusions?: { code: string; name?: unknown; description?: unknown }[];
};
const BENEFIT_ICONS: LucideIcon[] = [ShieldCheck, Wrench, Users, FileText, MapPin];
const BENEFIT_TINTS = ["blue", "gold", "blue", "red", "green"] as const;

/**
 * Line-level product detail (design 59): the marketplace category, the licensed
 * insurers offering it (GET /public/institutions) and, when the catalogue is
 * readable by this account, the line's coverages and exclusions.
 */
export default function ProductDetail() {
  const { t, td, language } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const setProduct = useInsurance((s) => s.setProduct);
  const category = CATEGORIES.find((c) => c.id === id && c.id !== "more");
  const providers = useLoad(() => CustomerApi.institutions("insurer"), []);
  // Optional: the catalogue may be scoped to staff; a refusal simply hides the coverage cards.
  const lines = useLoad(() => api<CatalogueLine[]>("/catalogue/lines", { networkRetries: 0, timeoutMs: 8000 }).catch(() => [] as CatalogueLine[]), []);

  const offering = useMemo(
    () => (category ? (providers.data ?? []).filter((p) => (p.products ?? []).some((x) => category.lines.includes((x.line_code ?? "").toUpperCase()))) : []),
    [category, providers.data],
  );
  const line = useMemo(() => {
    if (!category) return null;
    const rows = Array.isArray(lines.data) ? lines.data : [];
    return rows.find((l) => category.lines.includes((l.code ?? "").toUpperCase())) ?? rows.find((l) => category.lines.some((c) => (l.code ?? "").toUpperCase().startsWith(c))) ?? null;
  }, [category, lines.data]);
  const coverages = (line?.coverages ?? []).map((c) => localized(c.name, language) || c.code).filter(Boolean);
  const exclusions = (line?.exclusions ?? []).map((c) => localized(c.name, language) || c.code).filter(Boolean);

  if (!category) {
    return (
      <Screen>
        <BrandHeader title={t("qtChooseCover")} back right="bell" />
        <EmptyState title={t("pdUnknown")} message={t("qtStep1")} action={t("qtChooseCover")} onPress={() => router.replace("/quote/product")} />
      </Screen>
    );
  }
  const Icon = category.icon;
  const title = td(`lineFamily_${category.id}`, t(category.label));
  const quote = () => {
    setProduct(category.id);
    router.push({ pathname: "/quote/risk", params: { product: category.id } });
  };

  return (
    <Screen
      footer={
        <CtaBar>
          <View style={s.ctaRow}>
            <Button variant="secondary" icon={BarChart3} label={t("pdCompareOffers")} onPress={() => router.push({ pathname: "/(customer)/(tabs)/explore", params: { cat: category.id } })} style={s.flex1} />
            <Button variant="gold" icon={ArrowRight} label={t("searchGetQuote")} onPress={quote} style={s.flex1} />
          </View>
        </CtaBar>
      }
    >
      <BrandHeader title={title} subtitle={t(category.caption)} back right="bell" />

      <View style={s.hero}>
        <View style={s.heroCopy}>
          <Text style={s.heroTitle}>{t(category.label)}</Text>
          <Text style={s.heroBody}>{line?.description ? localized(line.description, language) : t("pdHeroBody")}</Text>
        </View>
        <View style={s.heroArt}>
          <Icon size={72} color={colors.navy800} strokeWidth={1.2} />
        </View>
        {offering.length ? (
          <View style={s.heroLogos}>
            {offering.slice(0, 3).map((p, i) => (
              <View key={p.id} style={[s.heroLogo, i > 0 && s.heroLogoBorder]}>
                <InstitutionMark logoUrl={institutionLogo(p)} initials={p.initials} size={30} />
                <Text style={s.heroLogoText} numberOfLines={1}>{p.short_name ?? p.name}</Text>
              </View>
            ))}
          </View>
        ) : null}
      </View>

      {offering.length ? (
        <Pressable accessibilityRole="button" onPress={() => router.push("/institutions/insurers" as never)} style={({ pressed }) => [s.trust, pressed && s.pressed]}>
          <ShieldCheck size={22} color={colors.gold500} />
          <Text style={[s.trustText, s.flex]}>{t("pdLicensedProviders", { count: offering.length })}</Text>
          <ChevronRight size={20} color={colors.navy800} />
        </Pressable>
      ) : null}

      {coverages.length ? (
        <View style={s.block}>
          <SectionHeading title={t("pdKeyBenefits")} />
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={s.benefits}>
            {coverages.slice(0, 5).map((name, i) => (
              <IconTile key={`${name}-${i}`} icon={BENEFIT_ICONS[i % BENEFIT_ICONS.length] ?? ShieldCheck} tint={BENEFIT_TINTS[i % BENEFIT_TINTS.length] ?? "blue"} label={name} style={s.benefit} onPress={quote} />
            ))}
          </ScrollView>
        </View>
      ) : null}

      {coverages.length || exclusions.length ? (
        <View style={s.twoUp}>
          {coverages.length ? (
            <View style={[s.card, s.half]}>
              <View style={s.cardHead}>
                <FileText size={22} color={colors.blue600} />
                <Text style={s.cardTitle}>{t("pdWhatsCovered")}</Text>
              </View>
              <CheckList items={coverages.slice(0, 6)} tint="gold" compact plain />
            </View>
          ) : null}
          {exclusions.length ? (
            <View style={[s.card, s.half]}>
              <View style={s.cardHead}>
                <HeartHandshake size={22} color={colors.blue600} />
                <Text style={s.cardTitle}>{t("pdExclusions")}</Text>
              </View>
              <CheckList items={exclusions.slice(0, 6)} tint="gold" compact plain />
            </View>
          ) : null}
        </View>
      ) : null}

      <View style={s.block}>
        <SectionHeading title={t("pdTopProviders", { product: t(category.label) })} action={t("seeAll")} onAction={() => router.push("/institutions/insurers" as never)} />
        {providers.loading && !providers.data ? <LoadingState label={t("exploreLoadingProviders")} /> : null}
        {providers.error && !providers.data ? <ErrorState error={providers.error} onRetry={() => void providers.reload()} /> : null}
        {providers.data && !offering.length ? <Text style={s.meta}>{t("pdNoProviders")}</Text> : null}
        {offering.length ? (
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={s.strip}>
            {offering.map((p) => {
              const count = (p.products ?? []).filter((x) => category.lines.includes((x.line_code ?? "").toUpperCase())).length;
              return (
                <Pressable
                  key={p.id}
                  accessibilityRole="button"
                  accessibilityLabel={p.name}
                  onPress={() => router.push({ pathname: "/institutions/insurer/[id]", params: { id: p.id } })}
                  android_ripple={ripple()}
                  style={({ pressed }) => [s.providerCard, pressed && s.pressed]}
                >
                  <View style={s.providerTop}>
                    <InstitutionMark logoUrl={institutionLogo(p)} initials={p.initials} size={48} />
                    <View style={s.flex}>
                      <Text style={s.providerName} numberOfLines={1}>{p.short_name ?? p.name}</Text>
                      <Text style={s.meta}>{count === 1 ? t("productsCountOne") : t("productsCount", { count })}</Text>
                    </View>
                    <ChevronRight size={18} color={colors.blue600} />
                  </View>
                </Pressable>
              );
            })}
          </ScrollView>
        ) : null}
      </View>
      {!offering.length && providers.data ? <Button label={t("searchGetQuote")} onPress={quote} /> : null}
    </Screen>
  );
}

const s = StyleSheet.create({
  flex: { flex: 1 },
  pressed: { opacity: 0.85 },
  meta: { ...type.meta, color: colors.neutral600 },
  hero: { backgroundColor: colors.blue50, borderRadius: radius.feature, padding: space.x4, gap: space.x3, overflow: "hidden" },
  heroCopy: { paddingRight: 96, gap: space.x2 },
  heroTitle: { fontFamily: "Inter_700Bold", fontSize: 22, lineHeight: 28, color: colors.navy950 },
  heroBody: { ...type.body, color: colors.neutral700 },
  heroArt: { position: "absolute", right: 12, top: 12, width: 96, height: 96, borderRadius: 48, backgroundColor: colors.white, alignItems: "center", justifyContent: "center", opacity: 0.95 },
  heroLogos: { flexDirection: "row", borderTopWidth: 1, borderTopColor: colors.blue100, paddingTop: space.x3 },
  heroLogo: { flex: 1, flexDirection: "row", alignItems: "center", gap: 6, paddingRight: space.x2 },
  heroLogoBorder: { borderLeftWidth: 1, borderLeftColor: colors.blue100, paddingLeft: space.x2 },
  heroLogoText: { ...type.caption, color: colors.navy950, flexShrink: 1 },
  trust: { flexDirection: "row", alignItems: "center", gap: space.x3, backgroundColor: colors.white, borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.card, padding: space.x4 },
  trustText: { ...type.label, color: colors.navy950 },
  block: { gap: space.x3 },
  benefits: { gap: space.x2, paddingRight: space.x2 },
  benefit: { width: 104, minWidth: 104, flexGrow: 0, flexShrink: 0, flexBasis: 104 },
  twoUp: { flexDirection: "row", flexWrap: "wrap", gap: space.x3 },
  half: { flexGrow: 1, flexShrink: 1, flexBasis: 165 },
  card: { backgroundColor: colors.white, borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.feature, padding: space.x3, gap: space.x2 },
  cardHead: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  cardTitle: { ...type.label, fontSize: 16, lineHeight: 22, color: colors.navy950, flexShrink: 1 },
  strip: { gap: space.x3, paddingRight: space.x2 },
  providerCard: { width: 220, backgroundColor: colors.white, borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.feature, padding: space.x3, overflow: "hidden" },
  providerTop: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  providerName: { ...type.label, color: colors.navy950 },
  ctaRow: { flexDirection: "row", gap: space.x3 },
  flex1: { flex: 1 },
  compareBtn: { flex: 1, minHeight: 52, borderRadius: radius.card, borderWidth: 1.5, borderColor: colors.blue600, backgroundColor: colors.blue50, flexDirection: "row", alignItems: "center", justifyContent: "center", gap: space.x2, overflow: "hidden" },
  compareText: { ...type.label, color: colors.blue600 },
  goldBtn: { flex: 1, minHeight: 52, borderRadius: radius.card, backgroundColor: colors.gold500, flexDirection: "row", alignItems: "center", justifyContent: "center", gap: space.x2, overflow: "hidden" },
  goldText: { ...type.label, fontSize: 16, color: colors.navy950 },
});
