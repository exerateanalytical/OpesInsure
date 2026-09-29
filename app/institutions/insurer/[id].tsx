import React from "react";
import { Image, Linking, Pressable, ScrollView, Share, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import {
  Building2,
  ChevronRight,
  ExternalLink,
  FileText,
  Globe,
  Headset,
  LucideIcon,
  Mail,
  MapPin,
  Navigation,
  Package,
  Phone,
  Scale,
  Share2,
  ShieldCheck,
} from "lucide-react-native";
import { Button, Card, ripple, Screen, SectionTitle, StatusChip } from "@/components/ui";
import { BrandHeader, CtaBar, HeaderIconButton, SectionHeading } from "@/components/design";
import { StatePanel } from "@/components/StatePanel";
import { InstitutionMark, institutionLogo } from "@/components/InstitutionMark";
import { CATEGORY_TINT } from "@/components/customer/CategoryTiles";
import { productCategory } from "@/components/claims/claimProduct";
import { useLoad } from "@/hooks/useLoad";
import { InstitutionsApi, type Institution } from "@/api/extra";
import { useTranslation } from "@/i18n";
import {
  REGISTER_SOURCE_KEY,
  groupBranchesByCity,
  readDirectory,
  telUrl,
  verificationText,
} from "@/lib/institutions";
import { useInsurance } from "@/store/insurance";
import { useSession } from "@/store/session";
import { colors, radius, space, tileIcon, tileIconSize, type } from "@/theme/tokens";

const heroArt = require("../../../assets/brand/header_network.png");

const open = (url: string | null) => {
  if (url) void Linking.openURL(url).catch(() => undefined);
};
/** Maps search for an address (Google Maps URL works on Android, iOS and web). */
const mapsUrl = (q: string) => `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(q)}`;

/** Approved letterhead legal footer lines (public display only); [] when absent. */
const legalFooter = (row: unknown): string[] => {
  const v = (row as { legal_footer?: unknown } | null)?.legal_footer;
  return Array.isArray(v) ? v.filter((l): l is string => typeof l === "string" && !!l.trim()) : [];
};

/** Insurer company profile (zenith_insurance_cameroon_app_profile.png) from GET /public/institutions/{id}. */
export default function InsurerDetail() {
  const { t } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useLoad(() => InstitutionsApi.show(id), [id]);
  const status = useSession((s) => s.status);
  const setProduct = useInsurance((s) => s.setProduct);
  const compare = (lineCode: string) => {
    if (status !== "authenticated") {
      router.push("/(auth)/sign-in");
      return;
    }
    setProduct(lineCode.toLowerCase());
    router.push("/quote/risk");
  };
  const insurer = q.data;
  const firstProduct = insurer?.products?.[0];
  const share = () => {
    if (!insurer) return;
    const d = readDirectory(insurer);
    void Share.share({ message: [insurer.short_name ?? insurer.name, d.website].filter(Boolean).join("\n") }).catch(() => undefined);
  };
  return (
    <Screen
      footer={
        insurer ? (
          <CtaBar>
            <View style={styles.ctaRow}>
              <View style={styles.flex}>
                <Button label={t("insurerCompareShort")} icon={Scale} variant="secondary" onPress={() => router.push("/quote/product")} />
              </View>
              {firstProduct ? (
                <>
                  <View style={styles.flex}>
                    <Button label={t("insurerViewProducts")} icon={Package} onPress={() => router.push({ pathname: "/quote/product", params: { product: productCategory(firstProduct.name, firstProduct.line_code)?.id ?? "" } })} />
                  </View>
                  <Pressable accessibilityRole="button" accessibilityLabel={t("propGetQuote")} onPress={() => compare(firstProduct.line_code)} android_ripple={ripple()} style={({ pressed }) => [styles.gold, pressed && styles.pressed]}>
                    <FileText size={18} color={colors.navy950} />
                    <Text style={styles.goldText} numberOfLines={1}>{t("insurerGetQuote")}</Text>
                  </Pressable>
                </>
              ) : null}
            </View>
          </CtaBar>
        ) : undefined
      }
    >
      <BrandHeader back right={insurer ? <HeaderIconButton icon={Share2} label={t("insurerShare")} onPress={share} /> : null} />
      <StatePanel {...q} onRetry={q.reload} isEmpty={() => false} loadingLabel={t("loadingInsurers")}>
        {(insurer) => <Profile insurer={insurer} compare={compare} />}
      </StatePanel>
    </Screen>
  );
}

function Profile({ insurer, compare }: { insurer: Institution; compare: (line: string) => void }) {
  const { t, language } = useTranslation();
  const [allProducts, setAllProducts] = React.useState(false);
  const d = readDirectory(insurer);
  const phone = d.phones.find((p) => telUrl(p));
  const email = d.emails[0];
  const address = d.hq ? [d.hq.address, d.hq.city, "Cameroun"].filter(Boolean).join(", ") : null;
  const products = insurer.products ?? [];
  const cats = Array.from(
    new Map(
      products
        .map((p) => productCategory(p.name, p.line_code))
        .filter((c): c is NonNullable<typeof c> => !!c)
        .map((c) => [c.id, c]),
    ).values(),
  );
  const branchCount = insurer.branch_count ?? d.branches.length;
  const cities = Array.from(new Set(d.branches.map((b) => b.city).filter((c): c is string => !!c)));
  const verified = d.verification ? verificationText(d.verification, language, t) : insurer.is_official_register && insurer.licensed ? t("licensedStatus") : null;
  const actions: { icon: LucideIcon; label: string; onPress: () => void }[] = [
    ...(phone ? [{ icon: Phone, label: t("insurerCall"), onPress: () => open(telUrl(phone)) }] : []),
    ...(email ? [{ icon: Mail, label: t("insurerEmail"), onPress: () => open(`mailto:${email}`) }] : []),
    ...(d.website ? [{ icon: Globe, label: t("insurerWebsite"), onPress: () => open(d.website) }] : []),
    ...(address ? [{ icon: MapPin, label: t("insurerDirections"), onPress: () => open(mapsUrl(address)) }] : []),
  ];
  const featured = allProducts ? products : products.slice(0, 2);
  return (
    <>
      {/* Hero: logo tile, full name, verification chip, contact actions. */}
      <View style={styles.hero}>
        <View style={styles.heroArt} pointerEvents="none" accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
          <Image source={heroArt} style={styles.heroArtImg} resizeMode="contain" />
        </View>
        <View style={styles.heroRow}>
          <View style={styles.logoTile}>
            <InstitutionMark logoUrl={institutionLogo(insurer)} initials={insurer.initials} size={64} />
          </View>
          <View style={styles.flex}>
            <Text accessibilityRole="header" style={styles.title}>{insurer.name}</Text>
            {verified ? (
              <View style={styles.verified}>
                <ShieldCheck size={16} color={colors.successText} />
                <Text style={styles.verifiedText}>{verified}</Text>
              </View>
            ) : null}
          </View>
        </View>
        {insurer.legal_name && insurer.legal_name.toLowerCase() !== insurer.name.toLowerCase() ? <Text style={styles.body}>{insurer.legal_name}</Text> : null}
        <View style={styles.badges}>
          {insurer.branch ? (
            <StatusChip label={t(insurer.branch === "LIFE" ? "branchBadgeLIFE" : "branchBadgeIARD")} tone={insurer.branch === "LIFE" ? "success" : "info"} />
          ) : null}
          {d.verification && insurer.is_official_register && insurer.licensed ? <StatusChip label={t("licensedStatus")} tone="success" /> : null}
        </View>
        {insurer.canonical_id ? <Text style={styles.canonical}>{t("canonicalId", { id: insurer.canonical_id })}</Text> : null}
        {actions.length ? (
          <View style={styles.actions}>
            {actions.map((a) => (
              <Pressable key={a.label} accessibilityRole="button" accessibilityLabel={a.label} onPress={a.onPress} android_ripple={ripple()} style={({ pressed }) => [styles.action, pressed && styles.pressed]}>
                <View style={styles.actionIcon}>
                  <a.icon size={20} color={colors.blue600} />
                </View>
                <Text style={styles.actionText} numberOfLines={2}>{a.label}</Text>
              </Pressable>
            ))}
          </View>
        ) : null}
      </View>

      {/* Stats strip: three cells with dividers. */}
      <View style={styles.stats}>
        <Stat icon={Package} value={String(products.length)} label={t("insurerStatProducts")} />
        {branchCount ? (
          <>
            <View style={styles.statDivider} />
            <Stat icon={Building2} value={String(branchCount)} label={t("insurerStatBranches")} />
          </>
        ) : null}
        {d.hq?.city ? (
          <>
            <View style={styles.statDivider} />
            <Stat icon={MapPin} value={d.hq.city} label={t("headOffice")} />
          </>
        ) : null}
      </View>

      {cats.length ? (
        <>
          <SectionHeading title={t("insurerCategories")} action={t("seeAll")} onAction={() => router.push("/quote/product")} />
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.cats}>
            {cats.map((c) => {
              const tint = CATEGORY_TINT[c.id];
              const line = products.find((p) => productCategory(p.name, p.line_code)?.id === c.id)?.line_code ?? c.id;
              return (
                <Pressable
                  key={c.id}
                  accessibilityRole="button"
                  accessibilityLabel={t(c.label)}
                  onPress={() => compare(line)}
                  android_ripple={ripple()}
                  style={({ pressed }) => [styles.cat, { backgroundColor: tint.bg }, pressed && styles.pressed]}
                >
                  <c.icon size={tileIconSize(48)} color={tint.fg} strokeWidth={tileIcon.stroke} />
                  <Text style={styles.catText} numberOfLines={2}>{t(c.label)}</Text>
                </Pressable>
              );
            })}
          </ScrollView>
        </>
      ) : null}

      <SectionHeading
        title={t("insurerFeaturedProducts")}
        action={products.length > 2 ? (allProducts ? t("insurerShowLess") : t("seeAll")) : undefined}
        onAction={() => setAllProducts((v) => !v)}
      />
      {products.length ? (
        <View style={styles.productGrid}>
          {featured.map((p) => {
            const cat = productCategory(p.name, p.line_code);
            const tint = cat ? CATEGORY_TINT[cat.id] : { bg: colors.blue50, fg: colors.navy900 };
            const Icon = cat?.icon ?? ShieldCheck;
            return (
              <Pressable
                key={p.id}
                accessibilityRole="button"
                accessibilityLabel={`${p.name}. ${t("compareThisProduct")}`}
                onPress={() => compare(p.line_code)}
                android_ripple={ripple()}
                style={({ pressed }) => [styles.product, pressed && styles.pressed]}
              >
                <View style={styles.productTop}>
                  <View style={[styles.productIcon, { backgroundColor: tint.bg }]}>
                    <Icon size={tileIconSize(52)} color={tint.fg} strokeWidth={tileIcon.stroke} />
                  </View>
                  <View style={styles.chevron}>
                    <ChevronRight size={16} color={colors.navy900} />
                  </View>
                </View>
                <Text style={styles.productName} numberOfLines={3}>{p.name}</Text>
                <StatusChip label={cat ? t(cat.label) : p.line_code} tone="info" />
                <Text style={styles.productCta}>{t("compareThisProduct")}</Text>
              </Pressable>
            );
          })}
        </View>
      ) : (
        <Card>
          <Text style={styles.offer}>{t("noPlatformProducts")}</Text>
          <Text style={styles.body}>{t("noPlatformProductsBody")}</Text>
        </Card>
      )}

      {insurer.product_families?.length ? (
        <>
          <SectionHeading title={t("insurerAbout", { name: insurer.short_name ?? insurer.name })} />
          <View style={styles.about}>
            <View style={styles.aboutIcon}>
              <FileText size={tileIconSize(48)} color={colors.blue600} strokeWidth={tileIcon.stroke} />
            </View>
            <View style={styles.copy}>
              <Text style={styles.aboutLabel}>{t("publishedFamiliesUnverified")}</Text>
              <View style={styles.families}>
                {insurer.product_families.map((f) => (
                  <Text key={f} style={styles.family}>{f}</Text>
                ))}
              </View>
            </View>
          </View>
        </>
      ) : null}

      {d.phones.length || d.emails.length || d.website || branchCount ? (
        <View style={styles.twoUp}>
          {d.phones.length || d.emails.length || d.website ? (
            <View style={styles.infoCard}>
              <View style={styles.row}>
                <Headset size={22} color={colors.blue600} />
                <Text style={[styles.cardTitle, styles.flex]}>{t("insurerSupport")}</Text>
              </View>
              {d.phones.map((p) =>
                telUrl(p) ? (
                  <Pressable key={p} accessibilityRole="link" accessibilityLabel={t("callNumber", { phone: p })} onPress={() => open(telUrl(p))} style={styles.contactRow}>
                    <Phone size={16} color={colors.blue600} />
                    <Text style={styles.contactText}>{p}</Text>
                  </Pressable>
                ) : null,
              )}
              {d.emails.map((e) => (
                <Pressable key={e} accessibilityRole="link" accessibilityLabel={t("sendEmail", { email: e })} onPress={() => open(`mailto:${e}`)} style={styles.contactRow}>
                  <Mail size={16} color={colors.blue600} />
                  <Text style={styles.contactText} numberOfLines={2}>{e}</Text>
                </Pressable>
              ))}
              {d.website ? (
                <Pressable accessibilityRole="link" accessibilityLabel={t("openWebsite")} onPress={() => open(d.website)} style={styles.contactRow}>
                  <ExternalLink size={16} color={colors.blue600} />
                  <Text style={styles.contactText}>{t("openWebsite")}</Text>
                </Pressable>
              ) : null}
            </View>
          ) : null}
          {branchCount ? (
            <View style={styles.infoCard}>
              <View style={styles.row}>
                <Building2 size={22} color={colors.blue600} />
                <Text style={[styles.cardTitle, styles.flex]}>{t("insurerStatBranches")}</Text>
              </View>
              <Text style={styles.branchCount}>{branchCount}</Text>
              {cities.length ? <Text style={styles.body} numberOfLines={4}>{cities.join(" · ")}</Text> : null}
            </View>
          ) : null}
        </View>
      ) : null}

      {d.hq ? (
        <View style={styles.location}>
          <View style={styles.locationHead}>
            <View style={styles.aboutIcon}>
              <MapPin size={tileIconSize(48)} color={colors.blue600} strokeWidth={tileIcon.stroke} />
            </View>
            <View style={styles.copy}>
              <Text style={styles.cardTitle}>{t("insurerOfficeLocation")}</Text>
              <Text style={styles.aboutLabel}>{t("headOffice")}</Text>
              {d.hq.address ? <Text style={styles.body}>{[d.hq.address, d.hq.city && !d.hq.address.includes(d.hq.city) ? d.hq.city : null].filter(Boolean).join(", ")}</Text> : d.hq.city ? <Text style={styles.body}>{d.hq.city}</Text> : null}
              {d.hq.po_box ? <Text style={styles.body}>{t("poBox", { box: d.hq.po_box })}</Text> : null}
            </View>
          </View>
          {address ? (
            <Pressable accessibilityRole="button" accessibilityLabel={t("insurerDirections")} onPress={() => open(mapsUrl(address))} android_ripple={ripple()} style={({ pressed }) => [styles.directions, pressed && styles.pressed]}>
              <Navigation size={18} color={colors.blue600} />
              <Text style={styles.directionsText}>{t("insurerDirections")}</Text>
            </Pressable>
          ) : null}
        </View>
      ) : null}

      <BranchNetwork branches={d.branches} />

      {legalFooter(insurer).length || insurer.is_official_register ? (
        <View style={styles.trust}>
          <View style={styles.trustIcon}>
            <ShieldCheck size={tileIconSize(52)} color={colors.blue600} strokeWidth={tileIcon.stroke} />
          </View>
          <View style={styles.copy}>
            <Text style={styles.cardTitle}>{t("insurerTrusted")}</Text>
            {legalFooter(insurer).map((line, i) => (
              <Text key={i} style={styles.legal}>{line}</Text>
            ))}
            {insurer.is_official_register ? <Text style={styles.legal}>{t(REGISTER_SOURCE_KEY)}</Text> : null}
          </View>
        </View>
      ) : null}
      {d.sources.length ? <Text style={styles.source}>{t("directorySources", { sources: d.sources.join(", ") })}</Text> : null}
    </>
  );
}

function Stat({ icon: Icon, value, label }: { icon: LucideIcon; value: string; label: string }) {
  return (
    <View style={styles.stat} accessible accessibilityLabel={`${value} ${label}`}>
      <View style={styles.statIcon}>
        <Icon size={20} color={colors.blue600} />
      </View>
      <Text style={styles.statValue} numberOfLines={2}>{value}</Text>
      <Text style={styles.statLabel} numberOfLines={2}>{label}</Text>
    </View>
  );
}

function BranchNetwork({ branches }: { branches: ReturnType<typeof readDirectory>["branches"] }) {
  const { t } = useTranslation();
  const groups = groupBranchesByCity(branches);
  if (!groups.length) return null;
  return (
    <>
      <SectionTitle title={t("branchNetwork", { count: branches.length })} />
      {groups.map((g) => (
        <Card key={g.city ?? "_other"}>
          <Text style={styles.offer}>{g.city ?? t("cityUnknown")}</Text>
          {g.branches.map((b, i) => (
            <View key={`${b.name}-${i}`} style={styles.branch}>
              {b.name ? <Text style={styles.branchName}>{b.name}</Text> : null}
              {b.address ? <Text style={styles.body}>{b.address}</Text> : null}
              {b.phone && telUrl(b.phone) ? (
                <Pressable
                  accessibilityRole="link"
                  accessibilityLabel={t("callNumber", { phone: b.phone })}
                  onPress={() => open(telUrl(b.phone!))}
                  style={styles.row}
                >
                  <Phone size={16} color={colors.blue700} />
                  <Text style={styles.link}>{b.phone}</Text>
                  <ChevronRight size={16} color={colors.blue700} />
                </Pressable>
              ) : null}
            </View>
          ))}
        </Card>
      ))}
    </>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  pressed: { opacity: 0.85 },
  ctaRow: { flexDirection: "row", gap: space.x2, alignItems: "stretch" },
  gold: { flex: 1, minHeight: 50, borderRadius: radius.control, backgroundColor: colors.gold500, flexDirection: "row", alignItems: "center", justifyContent: "center", gap: 6, paddingHorizontal: space.x2, overflow: "hidden" },
  goldText: { ...type.label, color: colors.navy950, flexShrink: 1 },
  hero: { backgroundColor: colors.white, borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.feature, padding: space.x4, gap: space.x3, overflow: "hidden" },
  heroArt: { position: "absolute", right: -30, top: -26, width: 110, height: 110, opacity: 0.22 },
  heroArtImg: { width: 110, height: 110 },
  heroRow: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  logoTile: { padding: 6, borderRadius: radius.feature, backgroundColor: colors.white, shadowColor: colors.navy950, shadowOpacity: 0.08, shadowRadius: 10, shadowOffset: { width: 0, height: 3 }, elevation: 3 },
  title: { fontFamily: "Inter_700Bold", fontSize: 22, lineHeight: 27, color: colors.navy950, letterSpacing: -0.3 },
  verified: { flexDirection: "row", alignItems: "center", gap: 6, alignSelf: "flex-start", backgroundColor: colors.successSoft, borderRadius: radius.pill, paddingHorizontal: space.x3, paddingVertical: 5, marginTop: space.x2 },
  verifiedText: { ...type.label, color: colors.successText },
  badges: { flexDirection: "row", gap: space.x2, flexWrap: "wrap" },
  actions: { flexDirection: "row", justifyContent: "space-around", borderTopWidth: 1, borderTopColor: colors.neutral100, paddingTop: space.x3 },
  action: { alignItems: "center", gap: 6, width: 76, minHeight: 48, paddingVertical: 4, borderRadius: radius.card, overflow: "hidden" },
  actionIcon: { width: 44, height: 44, borderRadius: 22, backgroundColor: colors.blue50, alignItems: "center", justifyContent: "center" },
  actionText: { ...type.meta, color: colors.navy950, textAlign: "center" },
  stats: { flexDirection: "row", alignItems: "center", backgroundColor: colors.white, borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.feature, paddingVertical: space.x3, paddingHorizontal: space.x2 },
  stat: { flex: 1, alignItems: "center", gap: 4, paddingHorizontal: space.x2 },
  statDivider: { width: 1, alignSelf: "stretch", backgroundColor: colors.neutral200 },
  statIcon: { width: 36, height: 36, borderRadius: 18, backgroundColor: colors.blue50, alignItems: "center", justifyContent: "center" },
  statValue: { ...type.cardTitle, fontSize: 17, lineHeight: 22, color: colors.navy950, textAlign: "center" },
  statLabel: { ...type.caption, fontFamily: "Inter_500Medium", color: colors.neutral600, textAlign: "center" },
  cats: { gap: space.x2, paddingVertical: 2 },
  cat: { width: 88, minHeight: 84, borderRadius: radius.card, alignItems: "center", justifyContent: "center", gap: 6, padding: space.x2, overflow: "hidden" },
  catText: { ...type.caption, color: colors.navy950, textAlign: "center" },
  productGrid: { flexDirection: "row", flexWrap: "wrap", gap: space.x3 },
  product: { flexGrow: 1, flexBasis: 150, backgroundColor: colors.white, borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.feature, padding: space.x3, gap: space.x2, overflow: "hidden" },
  productTop: { flexDirection: "row", alignItems: "flex-start", justifyContent: "space-between" },
  productIcon: { width: 52, height: 52, borderRadius: radius.card, alignItems: "center", justifyContent: "center" },
  productName: { ...type.label, fontSize: 15, lineHeight: 20, color: colors.navy950 },
  productCta: { ...type.label, color: colors.blue600 },
  chevron: { width: 30, height: 30, borderRadius: 15, borderWidth: 1, borderColor: colors.neutral200, alignItems: "center", justifyContent: "center" },
  about: { flexDirection: "row", gap: space.x3 },
  aboutIcon: { width: 48, height: 48, borderRadius: radius.card, backgroundColor: colors.blue50, alignItems: "center", justifyContent: "center" },
  aboutLabel: { ...type.label, color: colors.navy950 },
  twoUp: { flexDirection: "row", flexWrap: "wrap", gap: space.x3 },
  infoCard: { flexGrow: 1, flexBasis: 150, backgroundColor: colors.white, borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.feature, padding: space.x3, gap: space.x2 },
  contactRow: { flexDirection: "row", alignItems: "center", gap: space.x2, minHeight: 32 },
  contactText: { ...type.meta, color: colors.navy950, flexShrink: 1 },
  branchCount: { fontFamily: "Inter_700Bold", fontSize: 26, lineHeight: 32, color: colors.navy950 },
  locationHead: { flexDirection: "row", gap: space.x3 },
  location: { gap: space.x3, backgroundColor: colors.white, borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.feature, padding: space.x3 },
  directions: { alignSelf: "flex-start", flexDirection: "row", alignItems: "center", gap: 6, backgroundColor: colors.blue50, borderRadius: radius.control, paddingHorizontal: space.x3, minHeight: 44, overflow: "hidden" },
  directionsText: { ...type.label, color: colors.blue600 },
  row: { flexDirection: "row", alignItems: "center", gap: space.x2, minHeight: 32 },
  copy: { flex: 1, gap: 3 },
  cardTitle: { ...type.label, fontSize: 16, color: colors.navy950 },
  branch: { gap: 2, paddingTop: space.x2, borderTopWidth: 1, borderTopColor: colors.neutral100 },
  branchName: { ...type.label, color: colors.navy950 },
  link: { ...type.label, color: colors.blue700 },
  offer: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
  canonical: { ...type.meta, color: colors.neutral500, fontVariant: ["tabular-nums"] },
  families: { flexDirection: "row", flexWrap: "wrap", gap: 6, marginTop: 4 },
  family: {
    ...type.meta,
    color: colors.neutral700,
    borderWidth: 1,
    borderColor: colors.neutral200,
    borderRadius: radius.pill,
    paddingHorizontal: space.x2,
    paddingVertical: 2,
  },
  trust: { flexDirection: "row", gap: space.x3, padding: space.x4, borderRadius: radius.feature, backgroundColor: colors.blue50, borderWidth: 1, borderColor: colors.blue100 },
  trustIcon: { width: 52, height: 52, borderRadius: radius.card, backgroundColor: colors.white, alignItems: "center", justifyContent: "center" },
  legal: { ...type.meta, color: colors.neutral600 },
  source: { ...type.meta, color: colors.neutral500, textAlign: "center" },
});
