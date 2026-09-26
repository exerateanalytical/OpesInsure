import React from "react";
import { Linking, Pressable, Share, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import {
  Building2,
  ChevronRight,
  ExternalLink,
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
import { colors, radius, space, type } from "@/theme/tokens";

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
                <Button label={t("insurerCompareProducts")} icon={Scale} variant="secondary" onPress={() => router.push("/quote/product")} />
              </View>
              {firstProduct ? (
                <View style={styles.flex}>
                  <Button label={t("propGetQuote")} onPress={() => compare(firstProduct.line_code)} />
                </View>
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
  const actions: { icon: LucideIcon; label: string; onPress: () => void }[] = [
    ...(phone ? [{ icon: Phone, label: t("insurerCall"), onPress: () => open(telUrl(phone)) }] : []),
    ...(email ? [{ icon: Mail, label: t("insurerEmail"), onPress: () => open(`mailto:${email}`) }] : []),
    ...(d.website ? [{ icon: Globe, label: t("insurerWebsite"), onPress: () => open(d.website) }] : []),
    ...(address ? [{ icon: MapPin, label: t("insurerDirections"), onPress: () => open(mapsUrl(address)) }] : []),
  ];
  return (
    <>
      <Card feature>
        <View style={styles.heroRow}>
          <InstitutionMark logoUrl={institutionLogo(insurer)} initials={insurer.initials} size={88} />
          <View style={styles.flex}>
            <Text accessibilityRole="header" style={styles.title}>{insurer.short_name ?? insurer.name}</Text>
            <View style={styles.badges}>
              {d.verification ? <StatusChip label={verificationText(d.verification, language, t)} tone={d.verification.tone} /> : null}
              {insurer.is_official_register && insurer.licensed ? <StatusChip label={t("licensedStatus")} tone="success" /> : null}
              {insurer.branch ? (
                <StatusChip
                  label={t(insurer.branch === "LIFE" ? "branchBadgeLIFE" : "branchBadgeIARD")}
                  tone={insurer.branch === "LIFE" ? "success" : "info"}
                />
              ) : null}
            </View>
          </View>
        </View>
        {insurer.legal_name ? <Text style={styles.body}>{insurer.legal_name}</Text> : null}
        {insurer.canonical_id ? <Text style={styles.canonical}>{t("canonicalId", { id: insurer.canonical_id })}</Text> : null}
        {actions.length ? (
          <View style={styles.actions}>
            {actions.map((a) => (
              <Pressable key={a.label} accessibilityRole="button" accessibilityLabel={a.label} onPress={a.onPress} android_ripple={ripple()} style={({ pressed }) => [styles.action, pressed && styles.pressed]}>
                <View style={styles.actionIcon}>
                  <a.icon size={22} color={colors.blue600} />
                </View>
                <Text style={styles.actionText}>{a.label}</Text>
              </Pressable>
            ))}
          </View>
        ) : null}
      </Card>

      <View style={styles.stats}>
        <Stat icon={Package} value={String(products.length)} label={t("insurerStatProducts")} />
        {branchCount ? <Stat icon={Building2} value={String(branchCount)} label={t("insurerStatBranches")} /> : null}
        {d.hq?.city ? <Stat icon={MapPin} value={d.hq.city} label={t("headOffice")} /> : null}
      </View>

      {cats.length ? (
        <>
          <SectionHeading title={t("insurerCategories")} />
          <View style={styles.cats}>
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
                  <c.icon size={26} color={tint.fg} />
                  <Text style={styles.catText}>{t(c.label)}</Text>
                </Pressable>
              );
            })}
          </View>
        </>
      ) : null}

      <SectionHeading title={t("productsOnOpesInsure")} />
      {products.length ? (
        products.map((p) => {
          const cat = productCategory(p.name, p.line_code);
          const tint = cat ? CATEGORY_TINT[cat.id] : { bg: colors.blue50, fg: colors.navy900 };
          const Icon = cat?.icon ?? ShieldCheck;
          return (
            <Card key={p.id}>
              <View style={styles.productRow}>
                <View style={[styles.productIcon, { backgroundColor: tint.bg }]}>
                  <Icon size={28} color={tint.fg} />
                </View>
                <View style={styles.flex}>
                  <Text style={styles.offer}>{p.name}</Text>
                  <View style={styles.badges}>
                    <StatusChip label={p.line_code} tone="info" />
                  </View>
                </View>
              </View>
              <Button label={t("compareThisProduct")} variant="secondary" onPress={() => compare(p.line_code)} />
            </Card>
          );
        })
      ) : (
        <Card>
          <Text style={styles.offer}>{t("noPlatformProducts")}</Text>
          <Text style={styles.body}>{t("noPlatformProductsBody")}</Text>
        </Card>
      )}

      {d.phones.length || d.emails.length || d.website ? (
        <>
          <SectionTitle title={t("contactDetails")} />
          <Card>
            <View style={styles.row}>
              <Headset size={22} color={colors.blue600} />
              <Text style={styles.cardTitle}>{t("insurerSupport")}</Text>
            </View>
            {d.phones.map((p) =>
              telUrl(p) ? (
                <Button key={p} label={t("callNumber", { phone: p })} icon={Phone} variant="secondary" onPress={() => open(telUrl(p))} />
              ) : null,
            )}
            {d.emails.map((e) => (
              <Button key={e} label={t("sendEmail", { email: e })} icon={Mail} variant="secondary" onPress={() => open(`mailto:${e}`)} />
            ))}
            {d.website ? <Button label={t("openWebsite")} icon={ExternalLink} variant="tertiary" onPress={() => open(d.website)} /> : null}
          </Card>
        </>
      ) : null}

      {d.hq ? (
        <>
          <SectionTitle title={t("headOffice")} />
          <Card>
            <View style={styles.row}>
              <MapPin size={20} color={colors.blue600} />
              <View style={styles.copy}>
                {d.hq.address ? <Text style={styles.offer}>{d.hq.address}</Text> : null}
                {d.hq.city ? <Text style={styles.body}>{d.hq.city}</Text> : null}
                {d.hq.po_box ? <Text style={styles.body}>{t("poBox", { box: d.hq.po_box })}</Text> : null}
              </View>
            </View>
            {address ? <Button label={t("insurerDirections")} icon={Navigation} variant="tertiary" onPress={() => open(mapsUrl(address))} /> : null}
          </Card>
        </>
      ) : null}

      <BranchNetwork branches={d.branches} />

      {insurer.product_families?.length ? (
        <>
          <SectionTitle title={t("publishedFamiliesUnverified")} />
          <Card>
            <View style={styles.families}>
              {insurer.product_families.map((f) => (
                <Text key={f} style={styles.family}>{f}</Text>
              ))}
            </View>
          </Card>
        </>
      ) : null}

      {legalFooter(insurer).length || insurer.is_official_register ? (
        <View style={styles.trust}>
          <View style={styles.trustIcon}>
            <ShieldCheck size={26} color={colors.blue600} />
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
      <View style={styles.copy}>
        <Text style={styles.statValue}>{value}</Text>
        <Text style={styles.statLabel}>{label}</Text>
      </View>
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
  ctaRow: { flexDirection: "row", gap: space.x3 },
  heroRow: { flexDirection: "row", alignItems: "center", gap: space.x4 },
  title: { ...type.pageTitle, fontSize: 26, lineHeight: 32, color: colors.navy950 },
  badges: { flexDirection: "row", gap: space.x2, flexWrap: "wrap", marginTop: space.x2 },
  actions: { flexDirection: "row", justifyContent: "space-around", borderTopWidth: 1, borderTopColor: colors.neutral100, paddingTop: space.x3 },
  action: { alignItems: "center", gap: 6, minWidth: 64, minHeight: 48, paddingVertical: 4, borderRadius: radius.card, overflow: "hidden" },
  actionIcon: { width: 48, height: 48, borderRadius: 24, backgroundColor: colors.blue50, alignItems: "center", justifyContent: "center" },
  actionText: { ...type.meta, color: colors.navy950 },
  stats: { flexDirection: "row", flexWrap: "wrap", gap: space.x3, backgroundColor: colors.white, borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.card, padding: space.x4 },
  stat: { flexDirection: "row", alignItems: "center", gap: space.x2, flexGrow: 1, flexBasis: 140 },
  statIcon: { width: 40, height: 40, borderRadius: 20, backgroundColor: colors.blue50, alignItems: "center", justifyContent: "center" },
  statValue: { ...type.cardTitle, color: colors.navy950 },
  statLabel: { ...type.meta, color: colors.neutral600 },
  cats: { flexDirection: "row", flexWrap: "wrap", gap: space.x2 },
  cat: { width: 100, minHeight: 92, borderRadius: radius.card, alignItems: "center", justifyContent: "center", gap: 6, padding: space.x2, overflow: "hidden" },
  catText: { ...type.caption, color: colors.navy950, textAlign: "center" },
  productRow: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  productIcon: { width: 56, height: 56, borderRadius: radius.card, alignItems: "center", justifyContent: "center" },
  row: { flexDirection: "row", alignItems: "center", gap: space.x2, minHeight: 32 },
  copy: { flex: 1, gap: 3 },
  cardTitle: { ...type.label, fontSize: 16, color: colors.navy950 },
  branch: { gap: 2, paddingTop: space.x2, borderTopWidth: 1, borderTopColor: colors.neutral100 },
  branchName: { ...type.label, color: colors.navy950 },
  link: { ...type.label, color: colors.blue700 },
  offer: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
  canonical: { ...type.meta, color: colors.neutral500, fontVariant: ["tabular-nums"] },
  families: { flexDirection: "row", flexWrap: "wrap", gap: 6 },
  family: {
    ...type.meta,
    color: colors.neutral700,
    borderWidth: 1,
    borderColor: colors.neutral200,
    borderRadius: radius.pill,
    paddingHorizontal: space.x2,
    paddingVertical: 2,
  },
  trust: { flexDirection: "row", gap: space.x3, padding: space.x4, borderRadius: radius.card, backgroundColor: colors.blue50, borderWidth: 1, borderColor: colors.blue100 },
  trustIcon: { width: 52, height: 52, borderRadius: radius.card, backgroundColor: colors.white, alignItems: "center", justifyContent: "center" },
  legal: { ...type.meta, color: colors.neutral600 },
  source: { ...type.meta, color: colors.neutral500, textAlign: "center" },
});
