import React, { useEffect, useState } from "react";
import { ActivityIndicator, Pressable, StyleSheet, Switch, Text, View, Linking, Modal } from "react-native";
import { router } from "expo-router";
import { BarChart3, ChevronRight, Download, FileText, Lock, Megaphone, MessageCircle, Pencil, ShieldCheck, Trash2, UsersRound, ClipboardList, FileSignature, Gavel, HandCoins, History } from "lucide-react-native";
import type { LucideIcon } from "lucide-react-native";
import { PrivacyApi } from "@/api/account";
import type { Purpose } from "@/api/account";
import { Button, Card, ripple, Screen, StatusChip } from "@/components/ui";
import { Banner, BrandHeader, CtaBar, SectionHeading, TintedIcon } from "@/components/design";
import type { Tint } from "@/components/design";
import { ErrorState, LoadingState } from "@/components/StatePanel";
import { Preferences } from "@/store/preferences";
import { LegalLinks } from "@/components/LegalLinks";
import { LocationAutofillSetting } from "@/components/forms/LocationAutofill";
import { useLoad } from "@/hooks/useLoad";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";
import { roleToPortal, useSession } from "@/store/session";
import { useRuntime } from "@/store/runtime";
import { legalLinks } from "@/config/environment";
import { AgentButton, AgentCard, AgentNavRow, AgentSection, AgentShell, AgentSkeleton } from "@/components/agent";
import { agentColors as ac, agentLayout as AL, agentType as AT } from "@/theme/agent";

const PURPOSES: { code: Purpose; icon: LucideIcon; tint: Tint }[] = [
  { code: "MARKETING", icon: Megaphone, tint: "gold" },
  { code: "ANALYTICS", icon: BarChart3, tint: "green" },
  { code: "PARTNER_SHARING", icon: UsersRound, tint: "blue" },
  { code: "WHATSAPP_UPDATES", icon: MessageCircle, tint: "green" },
];

/**
 * Privacy, consent & legal. Consents are read from and saved to the server
 * (GET/PUT /mobile/account/consents, evidence-hashed per purpose). Export and
 * deletion are data-subject requests (POST /mobile/account/privacy-requests);
 * corrections go through a PRIVACY_REQUEST support case.
 */
export default function Privacy() {
  const { t, date } = useTranslation();
  const isAgent = useSession((st) => roleToPortal(st.activeWorkspace?.role_code) === "agent");
  const links = legalLinks(useRuntime((st) => st.bootstrap?.legal));
  const [showConsents, setShowConsents] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const q = useLoad(() => PrivacyApi.consents(), []);
  const dsr = useLoad(() => PrivacyApi.requests(), []);
  const [values, setValues] = useState<Record<string, boolean>>({});
  const [busy, setBusy] = useState<string | null>(null);
  const [msg, setMsg] = useState<{ text: string; ok: boolean } | null>(null);

  useEffect(() => {
    if (q.data) setValues(Object.fromEntries(q.data.map((c) => [c.purpose, c.granted])));
  }, [q.data]);
  const dirty = !!q.data && q.data.some((c) => values[c.purpose] !== c.granted);

  const save = async () => {
    setBusy("save");
    setMsg(null);
    try {
      await PrivacyApi.saveConsents(PURPOSES.map((p) => ({ purpose: p.code, granted: !!values[p.code] })));
      await q.reload();
      await Preferences.setMarketingConsent(!!values.MARKETING);
      setMsg({ text: t("savedToAccount"), ok: true });
    } catch (e) {
      setMsg({ text: e instanceof Error ? e.message : t("actionFailed"), ok: false });
    } finally {
      setBusy(null);
    }
  };
  const requestDsr = async (kind: "EXPORT" | "DELETE") => {
    setBusy(kind);
    setMsg(null);
    try {
      const r = await PrivacyApi.createRequest(kind);
      await dsr.reload();
      setMsg({ text: t("privacyRequestSent", { ref: r.reference ?? "" }), ok: true });
    } catch (e) {
      setMsg({ text: e instanceof Error ? e.message : t("actionFailed"), ok: false });
    } finally {
      setBusy(null);
    }
  };
  const correction = () =>
    router.push({
      pathname: "/support/new",
      params: { category: "PRIVACY_REQUEST", subject: t("privacyCorrectionSubject"), body: t("privacyCorrectionBody") },
    });

  if (isAgent) {
    // Screen 10 (AGENT_UI_SPEC_V2 §9.10): same consent/DSR logic as below, agent styling.
    const open = (url: string) => {
      setMsg(null);
      Linking.openURL(url).catch(() => setMsg({ text: t("openLinkFailed"), ok: false }));
    };
    const docs: { icon: LucideIcon; label: string; url?: string }[] = [
      { icon: ShieldCheck, label: t("privacyPolicy"), url: links.privacy },
      { icon: FileText, label: t("termsOfUse"), url: links.terms },
      { icon: FileSignature, label: t("agentDocAgreement") },
      { icon: HandCoins, label: t("agentDocCommission") },
      { icon: Gavel, label: t("agentDocComplaints") },
    ];
    return (
      <AgentShell variant="drilldown" title={t("agentPrivacyLegal")}>
        <AgentSection title={t("privacyLegalDocs")}>
          <AgentCard padded={false}>
            {docs.map((d, i) => (
              <AgentNavRow
                key={d.label}
                divider={i > 0}
                icon={d.icon}
                title={d.label}
                subtitle={d.url ? null : t("agentAvailableSoon")}
                chevron={!!d.url}
                onPress={d.url ? () => open(d.url as string) : undefined}
              />
            ))}
          </AgentCard>
        </AgentSection>

        <AgentSection title={t("agentDataPrivacy")}>
          <AgentCard padded={false}>
            <AgentNavRow divider={false} icon={Download} title={t("agentDownloadData")} subtitle={t("privacyExport")} busy={busy === "EXPORT"} onPress={() => void requestDsr("EXPORT")} />
            <AgentNavRow icon={Pencil} title={t("privacyCorrection")} subtitle={t("agentCorrectionSub")} onPress={correction} />
            <AgentNavRow
              icon={History}
              title={t("agentConsentHistory")}
              subtitle={t("privacyConsentPrefs")}
              chevron={false}
              right={<ChevronRight size={18} color={ac.muted} style={{ transform: [{ rotate: showConsents ? "90deg" : "0deg" }] }} />}
              onPress={() => setShowConsents((v) => !v)}
            />
            {showConsents ? (
              <View style={agentStyles.consents}>
                {q.loading && !q.data ? <AgentSkeleton rows={2} height={48} /> : null}
                {q.error && !q.data ? <ErrorState error={q.error} onRetry={q.reload} /> : null}
                <View style={agentStyles.consentRow}>
                  <Lock size={18} color={ac.secondary} />
                  <View style={styles.flex}>
                    <Text style={agentStyles.consentTitle}>{t("privacyProcessing")}</Text>
                    <Text style={agentStyles.consentMeta}>{t("privacyRequired")}</Text>
                  </View>
                </View>
                {(q.data ? PURPOSES : []).map((p) => {
                  const row = q.data?.find((c) => c.purpose === p.code);
                  return (
                    <View key={p.code} style={agentStyles.consentRow}>
                      <p.icon size={18} color={ac.navy} />
                      <View style={styles.flex}>
                        <Text style={agentStyles.consentTitle}>{t(`consent_${p.code}`)}</Text>
                        <Text style={agentStyles.consentMeta}>
                          {row?.updated_at ? t("agentConsentUpdated", { date: date(row.updated_at, true) }) : t("agentNeverRecorded")}
                          {row?.notice_version ? ` · v${row.notice_version}` : ""}
                        </Text>
                      </View>
                      <Switch
                        accessibilityLabel={t(`consent_${p.code}`)}
                        value={!!values[p.code]}
                        onValueChange={(v) => setValues((st) => ({ ...st, [p.code]: v }))}
                        trackColor={{ true: ac.actionBlue, false: ac.borderStrong }}
                        thumbColor={ac.surface}
                      />
                    </View>
                  );
                })}
                {q.data ? <AgentButton label={t("privacySaveConsents")} loading={busy === "save"} disabled={!dirty} onPress={() => void save()} /> : null}
                <LocationAutofillSetting />
              </View>
            ) : null}
          </AgentCard>
          {msg ? (
            <Text accessibilityLiveRegion="polite" accessibilityRole={msg.ok ? undefined : "alert"} style={msg.ok ? agentStyles.notice : agentStyles.error}>
              {msg.text}
            </Text>
          ) : null}
          {(dsr.data ?? []).length ? (
            <AgentCard padded={false}>
              {(dsr.data ?? []).map((r, i) => (
                <View key={r.id} style={[agentStyles.dsrRow, i > 0 && agentStyles.divider]}>
                  <View style={styles.flex}>
                    <Text style={agentStyles.consentTitle}>{t(r.type === "EXPORT" ? "privacyExport" : "privacyDelete")}</Text>
                    <Text style={agentStyles.consentMeta}>
                      {r.reference ?? ""}
                      {r.created_at ? ` · ${date(r.created_at)}` : ""}
                      {r.due_on ? ` · ${t("privacyDue", { date: date(r.due_on) })}` : ""}
                    </Text>
                  </View>
                  <StatusChip label={r.status} tone={["COMPLETED", "FULFILLED"].includes(r.status) ? "success" : "info"} />
                </View>
              ))}
            </AgentCard>
          ) : null}
        </AgentSection>

        <AgentSection title={t("agentDangerZone")}>
          <AgentCard tone="danger" style={agentStyles.danger}>
            <View style={agentStyles.consentRow}>
              <Trash2 size={22} color={ac.danger} />
              <View style={styles.flex}>
                <Text style={[agentStyles.consentTitle, { color: ac.danger }]}>{t("deleteAccount")}</Text>
                <Text style={agentStyles.consentMeta}>{t("privacyRetentionNote")}</Text>
              </View>
            </View>
            <AgentButton label={t("deleteAccount")} variant="danger" icon={Trash2} loading={busy === "DELETE"} onPress={() => setConfirmDelete(true)} />
          </AgentCard>
        </AgentSection>

        <Modal visible={confirmDelete} transparent animationType="slide" onRequestClose={() => setConfirmDelete(false)}>
          <Pressable accessibilityRole="button" accessibilityLabel={t("cancel")} style={agentStyles.scrim} onPress={() => setConfirmDelete(false)} />
          <View style={agentStyles.sheet} accessibilityViewIsModal>
            <View style={agentStyles.grabber} />
            <Text accessibilityRole="header" style={agentStyles.sheetTitle}>{t("agentDeleteConfirmTitle")}</Text>
            <Text style={agentStyles.sheetBody}>{t("agentDeleteConfirmBody")}</Text>
            <Text style={agentStyles.consentMeta}>{t("privacyRetentionNote")}</Text>
            <AgentButton
              label={t("agentDeleteConfirm")}
              variant="danger"
              icon={Trash2}
              loading={busy === "DELETE"}
              onPress={() => {
                setConfirmDelete(false);
                void requestDsr("DELETE");
              }}
            />
            <AgentButton label={t("agentDeleteWebPage")} variant="secondary" icon={ClipboardList} onPress={() => open(links.accountDeletion)} />
            <AgentButton label={t("cancel")} variant="secondary" onPress={() => setConfirmDelete(false)} />
          </View>
        </Modal>
      </AgentShell>
    );
  }
  return (
    <Screen
      footer={
        <CtaBar>
          <Button label={t("privacySaveConsents")} loading={busy === "save"} disabled={!dirty} onPress={() => void save()} />
        </CtaBar>
      }
    >
      <BrandHeader title={t("privacyPageTitle")} subtitle={t("privacyPageSubtitle")} back />
      <SectionHeading title={t("privacyConsentPrefs")} />
      {q.loading && !q.data ? <LoadingState /> : null}
      {q.error && !q.data ? <ErrorState error={q.error} onRetry={q.reload} /> : null}
      <Card style={styles.card}>
        <View style={styles.row}>
          <TintedIcon icon={ShieldCheck} tint="blue" size={44} />
          <View style={styles.flex}>
            <Text style={styles.title}>{t("privacyProcessing")}</Text>
            <Text style={styles.body}>{t("privacyProcessingBody")}</Text>
          </View>
          <View style={styles.required}>
            <Lock size={16} color={colors.neutral500} />
            <Text style={styles.meta}>{t("privacyRequired")}</Text>
          </View>
        </View>
        {q.data
          ? PURPOSES.map((p) => (
              <View key={p.code} style={[styles.row, styles.divider]}>
                <TintedIcon icon={p.icon} tint={p.tint} size={44} />
                <View style={styles.flex}>
                  <Text style={styles.title}>{t(`consent_${p.code}`)}</Text>
                  <Text style={styles.body}>{t(`consent_${p.code}_body`)}</Text>
                </View>
                <Switch
                  accessibilityLabel={t(`consent_${p.code}`)}
                  value={!!values[p.code]}
                  onValueChange={(v) => setValues((s) => ({ ...s, [p.code]: v }))}
                  trackColor={{ true: colors.blue600, false: colors.neutral300 }}
                  thumbColor={colors.white}
                />
              </View>
            ))
          : null}
      </Card>
      <Card style={styles.card}>
        <LocationAutofillSetting />
      </Card>
      {msg ? (
        <Text accessibilityLiveRegion="polite" accessibilityRole={msg.ok ? undefined : "alert"} style={msg.ok ? styles.notice : styles.error}>
          {msg.text}
        </Text>
      ) : null}

      <SectionHeading title={t("privacyLegalDocs")} />
      <LegalLinks />
      <Button label={t("termsAndPrivacy")} icon={FileText} variant="tertiary" onPress={() => router.push("/terms")} />

      <SectionHeading title={t("privacyYourData")} />
      <Card style={styles.card}>
        <Text style={styles.body}>{t("privacyYourDataBody")}</Text>
        <NavRow icon={Download} tint="blue" label={t("privacyExport")} busy={busy === "EXPORT"} onPress={() => void requestDsr("EXPORT")} />
        <NavRow icon={Pencil} tint="gold" label={t("privacyCorrection")} onPress={correction} />
        <NavRow icon={Trash2} tint="red" danger label={t("privacyDelete")} busy={busy === "DELETE"} onPress={() => void requestDsr("DELETE")} />
        {(dsr.data ?? []).map((r) => (
          <View key={r.id} style={[styles.row, styles.divider]}>
            <View style={styles.flex}>
              <Text style={styles.title}>{t(r.type === "EXPORT" ? "privacyExport" : "privacyDelete")}</Text>
              <Text style={styles.meta}>
                {r.reference ?? ""}
                {r.created_at ? ` · ${date(r.created_at)}` : ""}
                {r.due_on ? ` · ${t("privacyDue", { date: date(r.due_on) })}` : ""}
              </Text>
            </View>
            <StatusChip label={r.status} tone={["COMPLETED", "FULFILLED"].includes(r.status) ? "success" : "info"} />
          </View>
        ))}
      </Card>
      <Banner icon={ShieldCheck} tint="blue" title={t("privacyImportant")} body={t("privacyRetentionNote")} />
    </Screen>
  );
}
function NavRow({ icon, tint, label, onPress, busy, danger }: { icon: LucideIcon; tint: Tint; label: string; onPress: () => void; busy?: boolean; danger?: boolean }) {
  return (
    <Pressable accessibilityRole="button" accessibilityLabel={label} accessibilityState={{ busy: !!busy, disabled: !!busy }} disabled={busy} onPress={onPress} android_ripple={ripple()} style={({ pressed }) => [styles.row, styles.divider, styles.navRow, pressed && styles.pressed]}>
      <TintedIcon icon={icon} tint={tint} size={44} />
      <Text style={[styles.title, styles.flex, danger && styles.danger]}>{label}</Text>
      {busy ? <ActivityIndicator color={colors.blue600} /> : <ChevronRight size={20} color={danger ? colors.dangerText : colors.navy900} />}
    </Pressable>
  );
}
const agentStyles = StyleSheet.create({
  consents: { padding: AL.cardPadding, gap: 12, borderTopWidth: 1, borderTopColor: ac.border },
  consentRow: { flexDirection: "row", alignItems: "center", gap: 12 },
  consentTitle: { ...AT.cardTitle, color: ac.text },
  consentMeta: { ...AT.secondary, color: ac.secondary },
  dsrRow: { flexDirection: "row", alignItems: "center", gap: 12, padding: AL.cardPadding },
  divider: { borderTopWidth: 1, borderTopColor: ac.border },
  notice: { ...AT.secondary, color: ac.success },
  error: { ...AT.secondary, color: ac.danger },
  danger: { gap: 14 },
  scrim: { flex: 1, backgroundColor: "rgba(7,54,86,0.35)" },
  sheet: {
    backgroundColor: ac.surface,
    borderTopLeftRadius: 24,
    borderTopRightRadius: 24,
    padding: AL.screenPadding,
    paddingBottom: 32,
    gap: 12,
    width: "100%",
    maxWidth: 560,
    alignSelf: "center",
  },
  grabber: { alignSelf: "center", width: 40, height: 4, borderRadius: 2, backgroundColor: ac.borderStrong, marginBottom: 4 },
  sheetTitle: { ...AT.sectionTitle, color: ac.danger },
  sheetBody: { ...AT.body, color: ac.text },
});
const styles = StyleSheet.create({
  navRow: { minHeight: 56 },
  pressed: { opacity: 0.85 },
  danger: { color: colors.dangerText },
  card: { borderRadius: radius.feature, gap: space.x3 },
  row: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  divider: { borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: colors.neutral200, paddingTop: space.x3 },
  flex: { flex: 1 },
  required: { alignItems: "center", gap: 2 },
  title: { ...type.label, fontSize: 15, lineHeight: 20, color: colors.navy950 },
  body: { ...type.body, fontSize: 13, lineHeight: 18, color: colors.neutral600, marginTop: 2 },
  meta: { ...type.meta, color: colors.neutral600 },
  notice: { ...type.meta, color: colors.successText },
  error: { ...type.meta, color: colors.dangerText },
});
