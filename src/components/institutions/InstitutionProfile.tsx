import React, { ReactNode, useEffect, useState } from "react";
import { Image, Linking, Pressable, Share, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import {
  ChevronRight,
  ExternalLink,
  FileText,
  Globe,
  LucideIcon,
  Mail,
  MapPin,
  Navigation,
  Phone,
  Scale,
  Share2,
  ShieldCheck,
  Star,
} from "lucide-react-native";
import { Button, Card, ripple, Screen, StatusChip } from "@/components/ui";
import { BrandHeader, CtaBar, HeaderIconButton, SectionHeading, TintedIcon } from "@/components/design";
import { SummaryCard } from "@/components/forms/SummaryCard";
import { EmptyState, StatePanel } from "@/components/StatePanel";
import { InstitutionMark } from "@/components/InstitutionMark";
import { productCategory, productIcon, productTint } from "@/components/claims/claimProduct";
import { useLoad } from "@/hooks/useLoad";
import { InstitutionsApi, type Institution } from "@/api/extra";
import { useTranslation } from "@/i18n";
import {
  directionsUrl,
  groupBranchesByCity,
  hostLabel,
  hqAddress,
  institutionRoute,
  isNotFound,
  officeKind,
  quoteEntry,
  sourceLinks,
  telUrl,
  type DirectoryBranch,
  type InsurerDirectory,
} from "@/lib/institutions";
import { roleToPortal, useSession } from "@/store/session";
import { colors, radius, space, type } from "@/theme/tokens";

/**
 * Shared building blocks of the public insurer and broker profiles. Both pages follow one
 * structure: identity card → key facts → offering → contact → sources/disclaimer, with a
 * pinned "Get a quote" bar.
 */

const heroArt = require("../../../assets/brand/header_network.png");

const open = (url: string | null | undefined) => {
  if (url) void Linking.openURL(url).catch(() => undefined);
};

/** Purchasable lines of the customer quote flow (app/quote/product.tsx). */
const QUOTE_PRODUCTS = ["motor", "health", "travel", "home", "life", "business", "accident"];

/** "Get a quote" for the current session; `start(line)` preselects the line when it is purchasable. */
export function useStartQuote() {
  const status = useSession((s) => s.status);
  const role = useSession((s) => s.activeWorkspace?.role_code);
  const entry = quoteEntry(status, roleToPortal(role));
  const start = (lineCode?: string | null, productName?: string | null) => {
    if (entry === "sign-in") return router.push("/(auth)/sign-in");
    const id = lineCode || productName ? productCategory(productName ?? null, lineCode ?? null)?.id : undefined;
    router.push(id && QUOTE_PRODUCTS.includes(id) ? { pathname: "/quote/product", params: { product: id } } : "/quote/product");
  };
  return { entry, start };
}

/** Translated line name ("MOTOR" → "Motor" / "Automobile"); never a raw code. */
export function useLineLabel() {
  const { t, td } = useTranslation();
  return (line: string) => {
    const cat = productCategory(null, line);
    return cat && cat.id !== "more" ? t(cat.label) : td(`line_${line}`, line);
  };
}

/**
 * Screen frame of a profile: brand header with Share, loading / error / not-found states, a
 * redirect when the id belongs to the other institution type, and the pinned quote bar.
 */
export function InstitutionScreen({
  id,
  kind,
  loadingLabel,
  children,
}: {
  id: string;
  kind: "insurer" | "broker";
  loadingLabel: string;
  children: (row: Institution) => ReactNode;
}) {
  const { t } = useTranslation();
  const q = useLoad(() => InstitutionsApi.show(id), [id]);
  const row = q.data;
  const { entry, start } = useStartQuote();
  const mismatch = !!row && row.type !== kind;
  useEffect(() => {
    // Deep link to /institutions/insurer/<broker id> (or the reverse): open the right profile.
    if (row && mismatch) router.replace({ pathname: institutionRoute(row.type), params: { id: row.id } });
  }, [row, mismatch]);
  const share = () => {
    if (!row) return;
    const website = row.website || row.contacts?.website;
    const message = [row.name, t(kind === "broker" ? "instKindBroker" : "instKindInsurer"), row.canonical_id ? t("canonicalId", { id: row.canonical_id }) : null, website]
      .filter(Boolean)
      .join("\n");
    void Share.share({ message }).catch(() => undefined);
  };
  const notFound = !row && isNotFound(q.error);
  // An insurer with nothing on OpesInsure cannot be quoted: offer the comparison instead.
  // With exactly one product the quote starts on that product's line.
  const insurerProducts = kind === "insurer" ? (row?.products ?? []) : null;
  const compareOnly = entry === "quote" && insurerProducts !== null && insurerProducts.length === 0;
  const onlyProduct = insurerProducts?.length === 1 ? insurerProducts[0] : null;
  return (
    <Screen
      footer={
        row && !mismatch && entry ? (
          <CtaBar>
            <Button
              label={t(entry === "sign-in" ? "instSignInToQuote" : compareOnly ? "instCompareInsurers" : "propGetQuote")}
              icon={compareOnly ? Scale : FileText}
              onPress={() =>
                compareOnly ? router.push("/quote/product") : onlyProduct ? start(onlyProduct.line_code, onlyProduct.name) : start()
              }
            />
          </CtaBar>
        ) : undefined
      }
    >
      <BrandHeader back right={row && !mismatch ? <HeaderIconButton icon={Share2} label={t(kind === "broker" ? "brokerProfile" : "insurerShare")} onPress={share} /> : null} />
      {notFound ? (
        <EmptyState
          title={t("instNotFoundTitle")}
          message={t("instNotFoundBody")}
          action={t(kind === "broker" ? "browseAuthorizedBrokers" : "instBrowseInsurers")}
          onPress={() => router.replace(kind === "broker" ? "/institutions/brokers" : "/institutions/insurers")}
        />
      ) : (
        <StatePanel {...q} onRetry={q.reload} isEmpty={() => false} loadingLabel={loadingLabel}>
          {(r) => (mismatch ? null : children(r))}
        </StatePanel>
      )}
    </Screen>
  );
}

export type ContactAction = { icon: LucideIcon; label: string; onPress: () => void };

/** Call / Email / Website / Directions shortcuts from a directory record (only what exists). */
export function useContactActions(d: InsurerDirectory): ContactAction[] {
  const { t } = useTranslation();
  const phone = d.phones.map(telUrl).find(Boolean);
  const email = d.emails[0];
  const directions = directionsUrl(d.hq);
  return [
    ...(phone ? [{ icon: Phone, label: t("insurerCall"), onPress: () => open(phone) }] : []),
    ...(email ? [{ icon: Mail, label: t("insurerEmail"), onPress: () => open(`mailto:${email}`) }] : []),
    ...(d.website ? [{ icon: Globe, label: t("insurerWebsite"), onPress: () => open(d.website) }] : []),
    ...(directions ? [{ icon: MapPin, label: t("insurerDirections"), onPress: () => open(directions) }] : []),
  ];
}

/** Identity card: logo, name, kind line, status chips, register id and contact shortcuts. */
export function ProfileHero({
  logoUrl,
  initials,
  name,
  kindLine,
  chips,
  actions,
}: {
  logoUrl: string | null;
  initials: string;
  name: string;
  kindLine: string;
  chips: ReactNode;
  actions: ContactAction[];
}) {
  return (
    <View style={s.hero}>
      <View style={s.heroArt} pointerEvents="none" accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
        <Image source={heroArt} style={s.heroArtImg} resizeMode="contain" />
      </View>
      <View style={s.heroRow}>
        <View style={s.logoTile}>
          <InstitutionMark logoUrl={logoUrl} initials={initials} size={64} />
        </View>
        <View style={s.flex}>
          <Text accessibilityRole="header" style={s.title}>{name}</Text>
          <Text style={s.kind}>{kindLine}</Text>
        </View>
      </View>
      <View style={s.chips}>{chips}</View>
      {actions.length ? (
        <View style={s.actions}>
          {actions.map((a) => (
            <Pressable
              key={a.label}
              accessibilityRole="button"
              accessibilityLabel={a.label}
              onPress={a.onPress}
              android_ripple={ripple()}
              style={({ pressed }) => [s.action, pressed && s.pressed]}
            >
              <View style={s.actionIcon}>
                <a.icon size={20} color={colors.blue600} />
              </View>
              <Text style={s.actionText} numberOfLines={2}>{a.label}</Text>
            </Pressable>
          ))}
        </View>
      ) : null}
    </View>
  );
}

const CHIP_TONE = {
  success: [colors.successSoft, colors.successText],
  warning: [colors.warningSoft, colors.warningText],
  info: [colors.blue50, colors.blue700],
} as const;

/**
 * Directory verification chip ("Verified", "Partially verified"…) with a shield. Pass the
 * badge tone from verificationBadge(): PARTIALLY_VERIFIED is a warning, not a green tick.
 */
export function VerifiedChip({ label, tone = "success" }: { label: string; tone?: "success" | "warning" | "info" }) {
  const [bg, fg] = CHIP_TONE[tone];
  return (
    <View style={[s.verified, { backgroundColor: bg }]}>
      <ShieldCheck size={14} color={fg} />
      <Text style={[s.verifiedText, { color: fg }]}>{label}</Text>
    </View>
  );
}

/** Gold "Featured broker" pill (profile and broker list). */
export function FeaturedChip({ label }: { label: string }) {
  return (
    <View style={s.featured}>
      <Star size={12} color={colors.navy950} fill={colors.navy950} />
      <Text style={s.featuredText}>{label}</Text>
    </View>
  );
}

export type Fact = { label: string; value: string; tone?: "warning" };

/** "Key facts" summary card (label above value rows, SummaryCard look). */
export function KeyFacts({ facts, icon }: { facts: Fact[]; icon: LucideIcon }) {
  const { t } = useTranslation();
  if (!facts.length) return null;
  return (
    <SummaryCard icon={icon} title={t("instKeyFacts")}>
      {facts.map((f, i) => (
        <View key={f.label} style={[s.field, i > 0 && s.fieldDivider]} accessible accessibilityLabel={`${f.label}: ${f.value}`}>
          <Text style={s.fieldLabel}>{f.label}</Text>
          <Text style={[s.fieldValue, f.tone === "warning" && s.warning]} selectable>{f.value}</Text>
        </View>
      ))}
    </SummaryCard>
  );
}

/** Tappable list row: leading mark/icon, title, meta line, chevron. */
export function ProfileRow({
  lead,
  title,
  meta,
  onPress,
  first,
  accessibilityLabel,
}: {
  lead: ReactNode;
  title: string;
  meta?: string | null;
  /** Without it the row is informational (no chevron). */
  onPress?: () => void;
  first?: boolean;
  accessibilityLabel?: string;
}) {
  const body = (
    <>
      {lead}
      <View style={s.flex}>
        <Text style={s.rowTitle}>{title}</Text>
        {meta ? <Text style={s.meta} numberOfLines={2}>{meta}</Text> : null}
      </View>
    </>
  );
  if (!onPress) {
    return (
      <View accessible accessibilityLabel={accessibilityLabel ?? [title, meta].filter(Boolean).join(", ")} style={[s.row, !first && s.divider]}>
        {body}
      </View>
    );
  }
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel ?? [title, meta].filter(Boolean).join(", ")}
      onPress={onPress}
      android_ripple={ripple()}
      style={({ pressed }) => [s.row, !first && s.divider, pressed && s.pressed]}
    >
      {body}
      <ChevronRight size={18} color={colors.neutral500} />
    </Pressable>
  );
}

/** Card with the profile radius (every profile card uses radius.feature). */
export function ProfileCard({ children }: { children: ReactNode }) {
  return <Card style={s.card}>{children}</Card>;
}

/** Products grouped by line: one card per line, translated line title. */
export function ProductGroups<P extends { id: string; name: string }>({
  groups,
  lead,
  meta,
  onPress,
  accessibilityLabel,
}: {
  groups: { line: string; products: P[] }[];
  lead: (p: P, line: string) => ReactNode;
  meta?: (p: P) => string | null;
  onPress?: (p: P, line: string) => void;
  accessibilityLabel?: (p: P) => string;
}) {
  const lineLabel = useLineLabel();
  return (
    <>
      {groups.map((g) => (
        <ProfileCard key={g.line}>
          <Text style={s.lineTitle}>{lineLabel(g.line)}</Text>
          <View>
            {g.products.map((p, i) => (
              <ProfileRow
                key={p.id}
                first={i === 0}
                lead={lead(p, g.line)}
                title={p.name}
                meta={meta?.(p)}
                onPress={onPress ? () => onPress(p, g.line) : undefined}
                accessibilityLabel={accessibilityLabel?.(p)}
              />
            ))}
          </View>
        </ProfileCard>
      ))}
    </>
  );
}

/** Tinted category icon for a product row. */
export function ProductIcon({ name, line }: { name: string; line: string }) {
  return <TintedIcon icon={productIcon(name, line)} tint={productTint(name, line)} size={40} />;
}

/** Plain explanatory note in a profile card (empty offering, no contact…). */
export function EmptyNote({ text }: { text: string }) {
  return (
    <ProfileCard>
      <Text style={s.meta}>{text}</Text>
    </ProfileCard>
  );
}

/** Contact details (all phones, emails, website, head office + directions) as tappable rows. */
export function ContactCard({ d }: { d: InsurerDirectory }) {
  const { t } = useTranslation();
  const address = hqAddress(d.hq);
  const directions = directionsUrl(d.hq);
  const phones = d.phones.filter((p) => telUrl(p));
  const empty = !phones.length && !d.emails.length && !d.website && !address && !d.hq?.po_box;
  return (
    <>
      <SectionHeading title={t("instContact")} icon={Phone} />
      {empty ? (
        <EmptyNote text={t("brokerNoContact")} />
      ) : (
        <ProfileCard>
          <View>
            {phones.map((p, i) => (
              <ContactRow key={p} first={i === 0} icon={Phone} label={t("instPhone")} value={p} a11y={t("callNumber", { phone: p })} onPress={() => open(telUrl(p))} />
            ))}
            {d.emails.map((e, i) => (
              <ContactRow key={e} first={!phones.length && i === 0} icon={Mail} label={t("email")} value={e} a11y={t("sendEmail", { email: e })} onPress={() => open(`mailto:${e}`)} />
            ))}
            {d.website ? (
              <ContactRow first={!phones.length && !d.emails.length} icon={ExternalLink} label={t("insurerWebsite")} value={hostLabel(d.website)} a11y={t("openWebsite")} onPress={() => open(d.website)} />
            ) : null}
            {address || d.hq?.po_box ? (
              <View style={[s.field, !!(phones.length || d.emails.length || d.website) && s.fieldDivider]}>
                <Text style={s.fieldLabel}>{t("headOffice")}</Text>
                {address ? <Text style={s.fieldValue} selectable>{address}</Text> : null}
                {d.hq?.po_box ? <Text style={s.meta} selectable>{t("poBox", { box: d.hq.po_box })}</Text> : null}
                {directions ? (
                  <Pressable
                    accessibilityRole="link"
                    accessibilityLabel={t("insurerDirections")}
                    onPress={() => open(directions)}
                    android_ripple={ripple()}
                    style={({ pressed }) => [s.directions, pressed && s.pressed]}
                  >
                    <Navigation size={16} color={colors.blue600} />
                    <Text style={s.directionsText}>{t("insurerDirections")}</Text>
                  </Pressable>
                ) : null}
              </View>
            ) : null}
          </View>
        </ProfileCard>
      )}
    </>
  );
}

function ContactRow({ icon: Icon, label, value, a11y, onPress, first }: { icon: LucideIcon; label: string; value: string; a11y: string; onPress: () => void; first?: boolean }) {
  return (
    <Pressable
      accessibilityRole="link"
      accessibilityLabel={a11y}
      onPress={onPress}
      android_ripple={ripple()}
      style={({ pressed }) => [s.contact, !first && s.fieldDivider, pressed && s.pressed]}
    >
      <View style={s.flex}>
        <Text style={s.fieldLabel}>{label}</Text>
        <Text style={s.link} numberOfLines={2}>{value}</Text>
      </View>
      <View style={s.contactIcon}>
        <Icon size={18} color={colors.blue600} />
      </View>
    </Pressable>
  );
}

const OFFICES_PREVIEW = 4;

/** Offices grouped by city in one card; long networks collapse behind "Show all". */
export function OfficesSection({ branches }: { branches: DirectoryBranch[] }) {
  const { t } = useTranslation();
  const [all, setAll] = useState(false);
  if (!branches.length) return null;
  const shown = all ? branches : branches.slice(0, OFFICES_PREVIEW);
  const groups = groupBranchesByCity(shown);
  return (
    <>
      <SectionHeading title={t("instOffices", { count: branches.length })} icon={MapPin} />
      <ProfileCard>
        {groups.map((g, gi) => (
          <View key={g.city ?? "_other"} style={[s.cityGroup, gi > 0 && s.fieldDivider]}>
            <Text style={s.lineTitle}>{g.city ?? t("cityUnknown")}</Text>
            {g.branches.map((b, i) => {
              const kind = officeKind(b.type);
              const tel = b.phone ? telUrl(b.phone) : null;
              return (
                <View key={`${b.name}-${i}`} style={s.office}>
                  <View style={s.officeHead}>
                    {b.name ? <Text style={[s.rowTitle, s.flex]}>{b.name}</Text> : <View style={s.flex} />}
                    {kind === "head" ? <StatusChip label={t("headOffice")} tone="info" /> : null}
                  </View>
                  {b.address ? <Text style={s.meta}>{b.address}</Text> : null}
                  {tel ? (
                    <Pressable accessibilityRole="link" accessibilityLabel={t("callNumber", { phone: b.phone! })} onPress={() => open(tel)} hitSlop={6} style={({ pressed }) => [s.officePhone, pressed && s.pressed]}>
                      <Phone size={14} color={colors.blue700} />
                      <Text style={s.link}>{b.phone}</Text>
                    </Pressable>
                  ) : null}
                </View>
              );
            })}
          </View>
        ))}
        {branches.length > OFFICES_PREVIEW ? (
          <Button
            label={all ? t("insurerShowLess") : t("instShowAllOffices", { count: branches.length })}
            variant="tertiary"
            size="small"
            onPress={() => setAll((v) => !v)}
          />
        ) : null}
      </ProfileCard>
    </>
  );
}

/** Trust card (legal footer, register source, notes) and tappable sources, centred like the list pages. */
export function ProfileFooter({
  licensed,
  legalFooter,
  official,
  notes,
  sources,
}: {
  licensed: boolean;
  legalFooter: string[];
  official: boolean;
  notes: string[];
  sources: string[];
}) {
  const { t } = useTranslation();
  const links = sourceLinks(sources);
  const lines = [...legalFooter, ...(official ? [t("registerSource")] : []), ...notes];
  return (
    <>
      {lines.length ? (
        <View style={s.trust}>
          <TintedIcon icon={ShieldCheck} tint="blue" size={44} />
          <View style={s.flex}>
            {licensed ? <Text style={s.trustTitle}>{t("insurerTrusted")}</Text> : null}
            {lines.map((line, i) => (
              <Text key={`${i}-${line}`} style={s.legal}>{line}</Text>
            ))}
          </View>
        </View>
      ) : null}
      {links.length ? (
        <View style={s.sources}>
          <Text style={s.source}>{t("instSources")}</Text>
          {links.map((l) =>
            l.url ? (
              <Pressable key={l.url} accessibilityRole="link" accessibilityLabel={l.url} onPress={() => open(l.url)} hitSlop={6}>
                <Text style={[s.source, s.sourceLink]}>{l.label}</Text>
              </Pressable>
            ) : (
              <Text key={l.label} style={s.source}>{l.label}</Text>
            ),
          )}
        </View>
      ) : null}
    </>
  );
}

const s = StyleSheet.create({
  flex: { flex: 1, gap: 2 },
  pressed: { opacity: 0.85 },
  card: { borderRadius: radius.feature },
  hero: { backgroundColor: colors.white, borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.feature, padding: space.x4, gap: space.x3, overflow: "hidden" },
  heroArt: { position: "absolute", right: -30, top: -26, width: 110, height: 110, opacity: 0.22 },
  heroArtImg: { width: 110, height: 110 },
  heroRow: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  logoTile: { padding: 6, borderRadius: radius.feature, backgroundColor: colors.white, shadowColor: colors.navy950, shadowOpacity: 0.08, shadowRadius: 10, shadowOffset: { width: 0, height: 3 }, elevation: 3 },
  title: { ...type.sectionTitle, color: colors.navy950, letterSpacing: -0.3 },
  kind: { ...type.meta, color: colors.neutral600 },
  chips: { flexDirection: "row", flexWrap: "wrap", alignItems: "center", gap: space.x2 },
  verified: { flexDirection: "row", alignItems: "center", gap: 4, backgroundColor: colors.successSoft, borderRadius: radius.pill, paddingHorizontal: 10, paddingVertical: 4 },
  verifiedText: { ...type.caption, color: colors.successText },
  featured: { flexDirection: "row", alignItems: "center", gap: 4, alignSelf: "flex-start", backgroundColor: colors.gold500, borderRadius: radius.pill, paddingHorizontal: 10, paddingVertical: 4 },
  featuredText: { ...type.caption, fontFamily: "Inter_700Bold", color: colors.navy950 },
  actions: { flexDirection: "row", justifyContent: "space-around", borderTopWidth: 1, borderTopColor: colors.neutral100, paddingTop: space.x3 },
  action: { flex: 1, maxWidth: 88, alignItems: "center", gap: 6, minHeight: 48, paddingVertical: 4, borderRadius: radius.card, overflow: "hidden" },
  actionIcon: { width: 44, height: 44, borderRadius: 22, backgroundColor: colors.blue50, alignItems: "center", justifyContent: "center" },
  actionText: { ...type.caption, color: colors.navy950, textAlign: "center" },
  field: { paddingVertical: space.x3, gap: 2 },
  fieldDivider: { borderTopWidth: 1, borderTopColor: colors.neutral100 },
  fieldLabel: { ...type.meta, color: colors.neutral600 },
  fieldValue: { ...type.body, color: colors.navy950 },
  warning: { color: colors.warningText },
  row: { flexDirection: "row", alignItems: "center", gap: space.x3, minHeight: 56, paddingVertical: space.x2, overflow: "hidden" },
  divider: { borderTopWidth: 1, borderTopColor: colors.neutral100 },
  rowTitle: { ...type.label, color: colors.navy950 },
  meta: { ...type.meta, color: colors.neutral600 },
  lineTitle: { ...type.caption, color: colors.blue700, textTransform: "uppercase", letterSpacing: 0.6 },
  contact: { flexDirection: "row", alignItems: "center", gap: space.x3, minHeight: 56, paddingVertical: space.x2, overflow: "hidden" },
  contactIcon: { width: 36, height: 36, borderRadius: 18, backgroundColor: colors.blue50, alignItems: "center", justifyContent: "center" },
  link: { ...type.label, color: colors.blue700 },
  directions: { alignSelf: "flex-start", flexDirection: "row", alignItems: "center", gap: 6, backgroundColor: colors.blue50, borderRadius: radius.control, paddingHorizontal: space.x3, minHeight: 44, marginTop: space.x2, overflow: "hidden" },
  directionsText: { ...type.label, color: colors.blue600 },
  cityGroup: { gap: space.x2, paddingTop: space.x1 },
  office: { gap: 2, paddingBottom: space.x2 },
  officeHead: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  officePhone: { flexDirection: "row", alignItems: "center", gap: 6, minHeight: 32, alignSelf: "flex-start" },
  trust: { flexDirection: "row", gap: space.x3, padding: space.x4, borderRadius: radius.feature, backgroundColor: colors.blue50, borderWidth: 1, borderColor: colors.blue100 },
  trustTitle: { ...type.label, fontSize: 16, color: colors.navy950 },
  legal: { ...type.meta, color: colors.neutral600 },
  sources: { flexDirection: "row", flexWrap: "wrap", justifyContent: "center", alignItems: "center", columnGap: space.x2, rowGap: 2 },
  source: { ...type.meta, color: colors.neutral500, textAlign: "center" },
  sourceLink: { color: colors.blue700, textDecorationLine: "underline" },
});
