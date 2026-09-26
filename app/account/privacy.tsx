import React, { useEffect, useState } from "react";
import { StyleSheet, Switch, Text, View } from "react-native";
import { router } from "expo-router";
import { BarChart3, Download, FileText, Lock, Megaphone, MessageCircle, Pencil, ShieldCheck, Trash2, UsersRound } from "lucide-react-native";
import type { LucideIcon } from "lucide-react-native";
import { PrivacyApi } from "@/api/account";
import type { Purpose } from "@/api/account";
import { Button, Card, Screen, StatusChip } from "@/components/ui";
import { Banner, BrandHeader, CtaBar, SectionHeading, TintedIcon } from "@/components/design";
import type { Tint } from "@/components/design";
import { ErrorState, LoadingState } from "@/components/StatePanel";
import { Preferences } from "@/store/preferences";
import { LegalLinks } from "@/components/LegalLinks";
import { useLoad } from "@/hooks/useLoad";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

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
        <Button label={t("privacyExport")} icon={Download} variant="secondary" loading={busy === "EXPORT"} onPress={() => void requestDsr("EXPORT")} />
        <Button label={t("privacyCorrection")} icon={Pencil} variant="secondary" onPress={correction} />
        <Button label={t("privacyDelete")} icon={Trash2} variant="danger" loading={busy === "DELETE"} onPress={() => void requestDsr("DELETE")} />
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
const styles = StyleSheet.create({
  card: { borderRadius: radius.feature, gap: space.x3 },
  row: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  divider: { borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: colors.neutral200, paddingTop: space.x3 },
  flex: { flex: 1 },
  required: { alignItems: "center", gap: 2 },
  title: { ...type.label, fontSize: 16, lineHeight: 21, color: colors.navy950 },
  body: { ...type.body, fontSize: 14, lineHeight: 20, color: colors.neutral600, marginTop: 2 },
  meta: { ...type.meta, color: colors.neutral600 },
  notice: { ...type.meta, color: colors.successText },
  error: { ...type.meta, color: colors.dangerText },
});
