import React, { useCallback, useEffect, useRef, useState } from "react";
import { Linking, Pressable, ScrollView, StyleSheet, Text, View } from "react-native";
import { useSafeAreaInsets } from "react-native-safe-area-context";
import { router } from "expo-router";
import { openDocumentUrl } from "@/components/documents/openDocument";
import { Calendar, Car, ChevronRight, Coins, CreditCard, Download, FileText, Headset, Phone, RefreshCcw, Settings2, Shield, ShieldAlert, ShieldCheck, Truck } from "lucide-react-native";
import { Button, Card, Screen, StatusChip, ripple } from "@/components/ui";
import { BrandHeader, CheckList, HeroCard, HeroMeta, IconTile, SectionHeading, TintedIcon } from "@/components/design";
import { LoadingState } from "@/components/StatePanel";
import { FlowRow } from "@/components/FlowPrimitives";
import { ErrorCard, InfoRow, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { Claim, ClaimsApi, Payment, PaymentsApi, PolicyApi, SupportContacts, SupportContactsApi, WalletApi, WalletPolicy } from "@/api/client";
import { Institution, InstitutionsApi } from "@/api/extra";
import { RegulatoryApi } from "@/api/regulatory";
import { policyHeaderLabels, RegulatoryTerm } from "@/lib/regulatoryTerms";
import { humanize, normalizeCoverage, openableUrl, paymentStatusInfo, policyStatusInfo, unwrapPage } from "@/lib/purchase";
import { useFormatters } from "@/hooks/useFormatters";
import { PolicyDocumentsSection } from "@/components/policies/PolicyDocumentsSection";
import { BeneficiariesSection } from "@/components/policies/BeneficiariesSection";
import { colors, radius, space, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

function insuredLabel(p: WalletPolicy): string | null {
  if (typeof p.insured_object === "string") return p.insured_object;
  if (p.insured_object && typeof p.insured_object === "object") {
    const o = p.insured_object as Record<string, unknown>;
    return [o.label, o.registration_number, o.make, o.model].filter((x) => typeof x === "string" && x).join(" · ") || null;
  }
  if (p.risk_asset) return [p.risk_asset.label, p.risk_asset.registration_number].filter(Boolean).join(" · ") || null;
  return null;
}

/** Product class / plan name from the terms snapshot when the offer carries one. */
function coverageTypeLabel(p: WalletPolicy): string | null {
  const s = (p.terms_snapshot ?? {}) as Record<string, unknown>;
  const snap = (s.coverage_snapshot ?? {}) as Record<string, unknown>;
  for (const v of [snap.cover_type, snap.plan_name, snap.product_class, s.product_class, s.plan_name]) {
    if (typeof v === "string" && v) return humanize(v);
  }
  return null;
}

async function paymentsFor(policy: WalletPolicy): Promise<Payment[]> {
  const out: Payment[] = [];
  for (let page = 1; page <= 3; page++) {
    const r = await PaymentsApi.list(page);
    out.push(...r.items);
    if (!r.info.hasMore) break;
  }
  return out.filter((p) => (policy.proposal_id && p.proposal_id === policy.proposal_id) || p.policy_id === policy.id);
}

/** 0..1 progress of the policy term at `now`, and its length in months. */
function termProgress(startsAt: string | null | undefined, endsAt: string | null | undefined) {
  const start = Date.parse(startsAt ?? "");
  const end = Date.parse(endsAt ?? "");
  if (!Number.isFinite(start) || !Number.isFinite(end) || end <= start) return { pct: 0, months: null as number | null };
  const pct = Math.min(1, Math.max(0, (Date.now() - start) / (end - start)));
  return { pct, months: Math.max(1, Math.round((end - start) / (30.4375 * 86400000))) };
}

/**
 * Policy wallet detail, from the owned endpoint /mobile/wallet/policies/{id}
 * (the staff /policies/{id} route is tenant-wide). Payments and claims are
 * filtered to this policy; everything degrades to a message, never a crash.
 */
export function PolicyDetailView({ id }: { id: string }) {
  const f = useFormatters();
  const { t } = useTranslation();
  const insets = useSafeAreaInsets();
  const [p, setP] = useState<WalletPolicy | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<unknown>(null);
  const [payments, setPayments] = useState<Payment[] | null>(null);
  const [claims, setClaims] = useState<Claim[] | null>(null);
  const [certBusy, setCertBusy] = useState(false);
  const [certMessage, setCertMessage] = useState<string | null>(null);
  const [contactBusy, setContactBusy] = useState(false);
  const [terms, setTerms] = useState<RegulatoryTerm[] | null>(null);
  const [insurer, setInsurer] = useState<Institution | null>(null);
  const [support, setSupport] = useState<SupportContacts | null>(null);
  const scrollRef = useRef<ScrollView>(null);
  const docsY = useRef(0);
  const allDocsY = useRef(0);
  const paymentsY = useRef(0);
  // CIMA contract vocabulary (Police d'assurance, Souscripteur, Assuré, Prime totale); fallbacks until it loads.
  useEffect(() => {
    if (f.language !== "fr") return;
    let live = true;
    RegulatoryApi.terms("fr").then((t) => live && setTerms(t)).catch(() => undefined);
    return () => {
      live = false;
    };
  }, [f.language]);
  const labels = policyHeaderLabels(f.language, terms);

  const load = useCallback(async () => {
    if (!id) return;
    setLoading(true);
    setError(null);
    try {
      const policy = await WalletApi.policy(id);
      setP(policy);
      paymentsFor(policy).then(setPayments).catch(() => setPayments([]));
      ClaimsApi.list()
        .then((x) => setClaims(unwrapPage<Claim>(x).items.filter((c) => c.policy_id === policy.id)))
        .catch(() => setClaims([]));
      if (policy.carrier_id) InstitutionsApi.show(policy.carrier_id).then(setInsurer).catch(() => setInsurer(null));
      SupportContactsApi.get().then(setSupport).catch(() => setSupport(null));
    } catch (e) {
      setError(e);
    } finally {
      setLoading(false);
    }
  }, [id]);
  useEffect(() => {
    void load();
  }, [load]);

  const certificate = async () => {
    setCertBusy(true);
    setCertMessage(null);
    try {
      const cert = await PolicyApi.certificate(id);
      const url = openableUrl(cert.download_url);
      if (url) openDocumentUrl(url, t("pdCertificate"), cert.serial_number ? `certificate-${cert.serial_number}` : undefined);
      else setCertMessage(t("pdCertPending", { serial: cert.serial_number ?? "" }).replace("  ", " "));
    } catch (e) {
      const status = (e as { status?: number }).status;
      setCertMessage(status === 404 ? t("pdCertNone") : t("pdCertFailed"));
    } finally {
      setCertBusy(false);
    }
  };

  const contactProvider = async () => {
    if (!p) return;
    setContactBusy(true);
    try {
      const insurer = await InstitutionsApi.show(p.carrier_id).catch(() => null);
      if (insurer?.phone) return void (await Linking.openURL(`tel:${insurer.phone}`));
      const website = openableUrl(insurer?.website);
      if (website) return void (await Linking.openURL(website));
      const support = await SupportContactsApi.get();
      if (support?.whatsapp_url) return void (await Linking.openURL(support.whatsapp_url));
      if (support?.phone) return void (await Linking.openURL(`tel:${support.phone}`));
      if (support?.email) return void (await Linking.openURL(`mailto:${support.email}`));
      router.push("/support/new");
    } catch {
      router.push("/support/new");
    } finally {
      setContactBusy(false);
    }
  };

  /** Emergency helpline: the platform support line, then the insurer's own contact path. */
  const helpline = support?.phone ?? insurer?.phone ?? null;
  const callHelpline = () => {
    if (helpline) return void Linking.openURL(`tel:${helpline}`).catch(() => void contactProvider());
    void contactProvider();
  };

  const scrollTo = (y: number) => scrollRef.current?.scrollTo({ y: Math.max(0, y - space.x3), animated: true });

  if (loading && !p) return <Screen><BrandHeader title={t("pdTitle")} subtitle={t("pdSubtitle")} right={null} /><LoadingState label={t("pdLoading")} /></Screen>;
  if (!p) return <Screen><BrandHeader title={t("pdTitle")} subtitle={t("pdSubtitle")} right={null} /><ErrorCard error={error} fallback={t("pdLoadFailed")} onRetry={() => void load()} /></Screen>;

  const info = policyStatusInfo(p.status, f.language);
  const provider = p.carrier_name ?? p.carrier?.party?.display_name ?? t("licensedCarrier");
  const premium = p.premium_minor ?? p.terms_snapshot?.total_minor ?? null;
  const cover = normalizeCoverage(p.terms_snapshot?.coverage_snapshot, f.language);
  const insured = insuredLabel(p);
  const canRenew = ["active", "expired"].includes(info.bucket) && p.status !== "CANCELLATION_PENDING";
  const delivery = p.delivery ?? null;
  const term = termProgress(p.coverage_starts_at, p.coverage_ends_at);
  const coverageType = coverageTypeLabel(p) ?? (cover.coverages.length ? t("pdCoverageCount", { count: cover.coverages.length }) : "—");
  const isMotor = /motor|auto|vehic|moto/i.test(`${p.product_name ?? ""} ${coverageType}`) || !!p.risk_asset;
  const latestPayment = payments?.length ? [...payments].sort((a, b) => Date.parse(b.created_at ?? "") - Date.parse(a.created_at ?? ""))[0] : null;
  const latestInfo = latestPayment ? paymentStatusInfo(latestPayment.status, f.language) : null;
  const paymentChip =
    latestInfo?.tone === "success"
      ? { label: t("pdPaidUpToDate"), tone: "success" as const }
      : latestPayment
        ? { label: latestInfo?.tone === "danger" ? latestInfo.label : t("pdPaymentPending"), tone: latestInfo?.tone === "danger" ? ("danger" as const) : ("warning" as const) }
        : { label: payments === null ? t("paymentsLoading") : t("pdPaymentNone"), tone: "neutral" as const };
  const walletDocs = p.documents ?? [];

  const meta: HeroMeta[] = [
    { icon: FileText, label: t("pdPolicyNumber"), value: p.policy_number },
    { icon: isMotor ? Car : Shield, label: labels.insured, value: insured ?? "—" },
    { icon: Calendar, label: t("pdStartDate"), value: f.date(p.coverage_starts_at) },
    { icon: Calendar, label: t("pdExpiryDate"), value: f.date(p.coverage_ends_at), tone: info.bucket === "expired" ? "danger" : undefined },
    { icon: Coins, label: labels.totalPremium, value: premium === null ? "—" : f.xaf(premium) },
    { icon: ShieldCheck, label: t("pdCoverageType"), value: coverageType },
  ];

  const docCard = (key: string, title: string, subtitle: string | undefined, onPress: () => void, busy = false) => (
    <Pressable key={key} accessibilityRole="button" accessibilityLabel={title} accessibilityState={{ busy }} disabled={busy} onPress={onPress} android_ripple={ripple()} style={({ pressed }) => [st.docCard, pressed && st.pressed]}>
      <TintedIcon icon={FileText} tint={key === "certificate" ? "red" : "blue"} size={36} />
      <View style={st.flex}>
        <Text style={st.docCardTitle} numberOfLines={2}>{title}</Text>
        {subtitle ? <Text style={st.docCardMeta} numberOfLines={1}>{subtitle}</Text> : null}
      </View>
      <Download size={20} color={colors.blue600} />
    </Pressable>
  );

  return (
    <Screen scroll={false} style={st.noPad}>
      <ScrollView ref={scrollRef} style={st.flex} contentContainerStyle={[st.content, { paddingBottom: space.x16 + insets.bottom }]} showsVerticalScrollIndicator={false} keyboardShouldPersistTaps="handled">
        <BrandHeader title={t("pdTitle")} subtitle={t("pdSubtitle")} right={null} />
        {error ? <ErrorCard error={error} fallback={t("pdStale")} onRetry={() => void load()} /> : null}

        <HeroCard
          icon={isMotor ? Car : Shield}
          title={p.product_name ?? labels.policy}
          provider={provider}
          providerLogo={insurer?.logo_url ?? null}
          chip={<StatusChip label={info.label} tone={info.tone} />}
          lines={[`${labels.policy} · ${coverageType}`, info.note]}
          meta={meta}
        >
          {p.certificate_number || p.issued_at || cover.excessMinor !== null ? (
            <View style={st.extraRows}>
              {p.certificate_number ? <InfoRow label={t("pdCertificate")} value={p.certificate_number} /> : null}
              {p.issued_at ? <InfoRow label={t("pdIssued")} value={f.date(p.issued_at)} /> : null}
              {cover.excessMinor !== null ? <InfoRow label={t("pdExcess")} value={f.xaf(cover.excessMinor)} /> : null}
            </View>
          ) : null}
        </HeroCard>

        {cover.coverages.length ? (
          <View style={st.benefits}>
            <View style={st.rowTitle}>
              <TintedIcon icon={ShieldCheck} tint="gold" size={40} />
              <Text style={st.cardTitle}>{t("pdKeyBenefits")}</Text>
            </View>
            <CheckList columns={2} items={cover.coverages.map((c) => `${c.name}${c.optional ? t("pdOptional") : ""}${c.limitMinor !== null ? ` · ${f.xaf(c.limitMinor)}` : ""}`)} />
            {cover.exclusions.length ? <Text style={ps.meta}>{t("pdExcludes", { list: cover.exclusions.map((e) => e.name).join(", ") })}</Text> : null}
          </View>
        ) : null}

        <Card style={st.termCard} accessibilityLabel={t("pdPolicyTerm")}>
          <View style={st.rowTitle}>
            <Calendar size={22} color={colors.navy900} />
            <Text style={[st.cardTitle, st.flex]}>{t("pdPolicyTerm")}</Text>
            {term.months ? <Text style={st.termMonths}>{t("pdMonths", { count: term.months })}</Text> : null}
          </View>
          <View style={st.track} accessibilityRole="progressbar" accessibilityValue={{ min: 0, max: 100, now: Math.round(term.pct * 100) }}>
            <View style={[st.trackFill, { width: `${Math.round(term.pct * 100)}%` }]} />
            <View style={[st.knob, st.knobStart]} />
            <View style={[st.knob, st.knobEnd, term.pct >= 1 && st.knobStart]} />
          </View>
          <View style={st.termRow}>
            <View>
              <Text style={st.termDate}>{f.date(p.coverage_starts_at)}</Text>
              <Text style={st.termLabel}>{t("pdPolicyStart")}</Text>
            </View>
            <View style={st.alignEnd}>
              <Text style={st.termDate}>{f.date(p.coverage_ends_at)}</Text>
              <Text style={st.termLabel}>{t("pdPolicyExpiry")}</Text>
            </View>
          </View>
        </Card>

        <View style={st.tiles}>
          <IconTile icon={FileText} label={t("pdViewDocuments")} tint="blue" onPress={() => scrollTo(docsY.current)} />
          <IconTile icon={RefreshCcw} label={t("pdRenewPolicy")} tint="gold" disabled={!canRenew} onPress={() => router.push({ pathname: "/policy/[id]/renew", params: { id: p.id } })} />
          <IconTile icon={ShieldAlert} label={t("pdFileClaim")} tint="red" disabled={!info.claimable} onPress={() => router.push({ pathname: "/claim/new", params: { policyId: p.id } })} />
          <IconTile icon={Headset} label={t("pdContact")} tint="blue" disabled={contactBusy} onPress={() => void contactProvider()} />
        </View>
        {!info.claimable ? <Text style={ps.meta}>{t("pdClaimInForce")}</Text> : null}

        <View onLayout={(e) => (docsY.current = e.nativeEvent.layout.y)}>
          <Card>
            <SectionHeading icon={FileText} title={t("pdDocuments")} action={t("pdViewAll")} onAction={() => scrollTo(allDocsY.current)} />
            <View style={st.docGrid}>
              {docCard("certificate", t("pdOpenCertificate"), p.certificate_number ?? undefined, () => void certificate(), certBusy)}
              {walletDocs.slice(0, 3).map((d) => {
                const url = openableUrl(d.download_url);
                const title = d.label || humanize(d.type) || t("pdDocument");
                return docCard(d.id, title, d.issued_at ? t("docIssuedOn", { date: f.date(d.issued_at) }) : d.status ? humanize(d.status) : undefined, () => (url ? openDocumentUrl(url, title) : router.push({ pathname: "/documents/[id]", params: { id: d.id } })));
              })}
            </View>
            {certMessage ? <Text style={ps.meta}>{certMessage}</Text> : null}
            {walletDocs.slice(3).map((d) => {
              const url = openableUrl(d.download_url);
              return (
                <FlowRow
                  key={d.id}
                  icon={FileText}
                  title={d.label || humanize(d.type) || t("pdDocument")}
                  subtitle={d.issued_at ? f.date(d.issued_at) : undefined}
                  status={d.status}
                  onPress={() => (url ? openDocumentUrl(url, d.label || humanize(d.type) || t("pdDocument")) : router.push({ pathname: "/documents/[id]", params: { id: d.id } }))}
                />
              );
            })}
            {!walletDocs.length ? <Text style={ps.meta}>{t("pdDocsLater")}</Text> : null}
          </Card>
        </View>

        <View style={st.twoUp}>
          <Card style={st.flex} onPress={() => router.push({ pathname: "/policy/[id]/service", params: { id: p.id } })} accessibilityLabel={`${labels.insured}. ${insured ?? "—"}. ${t("pdChange")}`}>
            <View style={st.rowTitle}>
              {isMotor ? <Car size={22} color={colors.navy900} /> : <Shield size={22} color={colors.navy900} />}
              <Text style={[st.smallTitle, st.flex]}>{labels.insured}</Text>
              <ChevronRight size={18} color={colors.blue600} />
            </View>
            <Text style={st.smallBody}>{insured ?? "—"}</Text>
            <Text style={st.smallMeta}>{t("pdChange")}</Text>
          </Card>
          <Card style={st.flex} onPress={() => scrollTo(paymentsY.current)} accessibilityLabel={`${t("pdPaymentStatus")}. ${paymentChip.label}`}>
            <View style={st.rowTitle}>
              <CreditCard size={22} color={colors.navy900} />
              <Text style={[st.smallTitle, st.flex]}>{t("pdPaymentStatus")}</Text>
              <ChevronRight size={18} color={colors.blue600} />
            </View>
            <StatusChip label={paymentChip.label} tone={paymentChip.tone} />
            {paymentChip.tone === "success" ? <Text style={st.smallMeta}>{t("pdPaidTermBody")}</Text> : null}
            <Text style={st.smallMeta}>{t("pdNextDue", { date: f.date(p.coverage_ends_at) })}</Text>
          </Card>
        </View>

        <Pressable accessibilityRole="button" accessibilityLabel={`${t("pdEmergencyTitle")}. ${helpline ?? t("pdContact")}`} onPress={callHelpline} android_ripple={ripple()} style={({ pressed }) => [st.emergency, pressed && st.pressed]}>
          <Phone size={22} color={colors.danger} />
          <View style={st.flex}>
            <Text style={st.emergencyTitle}>{t("pdEmergencyTitle")}</Text>
            <Text style={st.emergencyBody}>{t("pdEmergencyBody")}</Text>
          </View>
          <View style={st.emergencyPill}>
            <Phone size={16} color={colors.danger} />
            <Text style={st.emergencyPillText} numberOfLines={1}>{helpline ?? t("pdContact")}</Text>
          </View>
        </Pressable>

        <View onLayout={(e) => (allDocsY.current = e.nativeEvent.layout.y)}>
          <PolicyDocumentsSection policyId={id} />
        </View>

        <BeneficiariesSection policyId={p.id} />

        <Button label={t("pdChange")} icon={Settings2} variant="secondary" onPress={() => router.push({ pathname: "/policy/[id]/service", params: { id: p.id } })} />

        {delivery ? (
          <Card>
            <View style={ps.row}>
              <Truck size={18} color={colors.blue600} />
              <Text style={ps.title}>{t("pdSticker")}</Text>
            </View>
            <StatusChip label={humanize(delivery.status)} tone="info" />
            {delivery.tracking_code ? <InfoRow label={t("pdTracking")} value={delivery.tracking_code} /> : null}
            {delivery.id ? <Button label={t("pdTrackSticker")} variant="secondary" onPress={() => router.push({ pathname: "/delivery/[id]", params: { id: delivery.id } })} /> : null}
          </Card>
        ) : null}

        <View onLayout={(e) => (paymentsY.current = e.nativeEvent.layout.y)}>
          <Card>
            <Text style={ps.title}>{t("pdPayments")}</Text>
            {payments === null ? <Text style={ps.meta}>{t("paymentsLoading")}</Text> : null}
            {payments?.length === 0 ? <Text style={ps.meta}>{t("pdNoPayments")}</Text> : null}
            {payments?.map((pay) => (
              <FlowRow key={pay.id} icon={CreditCard} title={f.xaf(pay.amount_minor)} subtitle={pay.created_at ? f.date(pay.created_at) : humanize(pay.provider)} status={paymentStatusInfo(pay.status, f.language).label} onPress={() => router.push({ pathname: "/payments/[id]", params: { id: pay.id } })} />
            ))}
          </Card>
        </View>

        <Card>
          <Text style={ps.title}>{t("pdClaims")}</Text>
          {claims === null ? <Text style={ps.meta}>{t("pdClaimsLoading")}</Text> : null}
          {claims?.length === 0 ? <Text style={ps.meta}>{t("pdNoClaims")}</Text> : null}
          {claims?.map((c) => (
            <FlowRow key={c.id} icon={ShieldAlert} title={c.claim_number ?? t("pdClaim")} subtitle={c.incident_at ? f.date(c.incident_at) : undefined} status={humanize(c.status)} onPress={() => router.push({ pathname: "/claim/[id]", params: { id: c.id } })} />
          ))}
        </Card>
      </ScrollView>
    </Screen>
  );
}

const st = StyleSheet.create({
  flex: { flex: 1 },
  pressed: { opacity: 0.85 },
  alignEnd: { alignItems: "flex-end" },
  noPad: { paddingHorizontal: 0 },
  content: { paddingHorizontal: space.x5, gap: space.x5 },
  extraRows: { gap: space.x2, borderTopWidth: 1, borderTopColor: colors.neutral200, paddingTop: space.x3 },
  rowTitle: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  cardTitle: { ...type.cardTitle, color: colors.navy950 },
  benefits: { backgroundColor: colors.gold50, borderRadius: radius.feature, padding: space.x4, gap: space.x3 },
  termCard: { borderRadius: radius.feature },
  termMonths: { ...type.label, color: colors.neutral600 },
  track: { height: 16, justifyContent: "center", marginHorizontal: 4 },
  trackFill: { position: "absolute", left: 0, height: 4, borderRadius: 2, backgroundColor: colors.blue600 },
  knob: { position: "absolute", width: 16, height: 16, borderRadius: 8, borderWidth: 2, borderColor: colors.neutral300, backgroundColor: colors.white },
  knobStart: { left: -4, backgroundColor: colors.blue600, borderColor: colors.blue600 },
  knobEnd: { right: -4 },
  termRow: { flexDirection: "row", justifyContent: "space-between", alignItems: "flex-start" },
  termDate: { ...type.label, fontSize: 15, color: colors.navy950 },
  termLabel: { ...type.meta, color: colors.neutral600 },
  tiles: { flexDirection: "row", gap: space.x2 },
  docGrid: { flexDirection: "row", flexWrap: "wrap", gap: space.x2 },
  docCard: { width: "48.5%", flexGrow: 1, flexDirection: "row", alignItems: "center", gap: space.x2, padding: space.x3, borderRadius: radius.card, borderWidth: 1, borderColor: colors.neutral200, backgroundColor: colors.white, overflow: "hidden" },
  docCardTitle: { ...type.label, color: colors.navy950 },
  docCardMeta: { ...type.caption, fontFamily: "Inter_500Medium", color: colors.neutral600, marginTop: 2 },
  twoUp: { flexDirection: "row", gap: space.x2 },
  smallTitle: { ...type.label, fontSize: 15, color: colors.navy950 },
  smallBody: { ...type.body, color: colors.neutral700 },
  smallMeta: { ...type.meta, color: colors.neutral600 },
  emergency: { flexDirection: "row", alignItems: "center", gap: space.x3, padding: space.x3, borderRadius: radius.card, backgroundColor: colors.dangerSoft, overflow: "hidden" },
  emergencyTitle: { ...type.label, color: colors.dangerText },
  emergencyBody: { ...type.meta, color: colors.neutral700, marginTop: 2 },
  emergencyPill: { flexDirection: "row", alignItems: "center", gap: 6, backgroundColor: colors.white, borderRadius: radius.pill, paddingHorizontal: 12, paddingVertical: 8, maxWidth: "45%" },
  emergencyPillText: { ...type.label, color: colors.dangerText, flexShrink: 1 },
});
