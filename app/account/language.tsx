import React, { useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { Bell, CheckCircle2, Circle, Languages, Banknote, CalendarDays, Clock3, Globe2 } from "lucide-react-native";
import { AccountApi } from "@/api/client";
import { Button, Card, Screen } from "@/components/ui";
import { Banner, BrandHeader, CtaBar, SectionHeading } from "@/components/design";
import { useSession, roleToPortal } from "@/store/session";
import { colors, radius, space, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";
import { AgentButton, AgentCard, AgentNavRow, AgentSection, AgentShell } from "@/components/agent";
import { TimezonePicker } from "@/components/TimezonePicker";
import { useTimezone } from "@/store/timezone";
import { agentColors as ac, agentLayout as AL, agentType as AT } from "@/theme/agent";

/**
 * Language & communication. The choice is saved to the server
 * (PUT /mobile/account/locale) and then applied to the whole app at once.
 * Channels and topics live on the notification-preferences screen.
 */
export default function Language() {
  const { t, date, language } = useTranslation();
  const isAgent = useSession((s) => roleToPortal(s.activeWorkspace?.role_code) === "agent");
  const timeZone = useTimezone((s) => s.timezone);
  const current = useSession((s) => s.language);
  const setLanguage = useSession((s) => s.setLanguage);
  const [value, setValue] = useState<"en" | "fr">(current);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  const save = async () => {
    setBusy(true);
    setError(null);
    setSaved(false);
    try {
      await AccountApi.setLocale(value);
      setLanguage(value);
      setSaved(true);
    } catch (e) {
      setError(e instanceof Error ? e.message : t("actionFailed"));
    } finally {
      setBusy(false);
    }
  };
  if (isAgent) {
    // Screen 09 (AGENT_UI_SPEC_V2 §9.9): same state and save as below, agent styling.
    const now = new Date();
    const timeExample = new Intl.DateTimeFormat(language === "fr" ? "fr-CM" : "en-CM", { timeStyle: "short", timeZone }).format(now);
    return (
      <AgentShell
        variant="drilldown"
        title={t("agentLangRegion")}
        footer={<AgentButton label={t("agentSaveChanges")} loading={busy} disabled={value === current} onPress={() => void save()} />}
      >
        <Text style={agentStyles.lead}>{t("agentLangRegionLead")}</Text>
        <AgentSection title={t("language")}>
          <View style={agentStyles.segment} accessibilityRole="radiogroup">
            {(
              [
                ["en", "English"],
                ["fr", "Français"],
              ] as const
            ).map(([code, label]) => {
              const on = value === code;
              return (
                <Pressable
                  key={code}
                  accessibilityRole="radio"
                  accessibilityState={{ checked: on }}
                  accessibilityLabel={label}
                  onPress={() => setValue(code)}
                  style={[agentStyles.segItem, on && agentStyles.segOn]}
                >
                  <Text style={[agentStyles.segText, on && agentStyles.segTextOn]}>{label}</Text>
                </Pressable>
              );
            })}
          </View>
          {error ? <Text accessibilityRole="alert" style={agentStyles.error}>{error}</Text> : null}
          {saved ? <Text accessibilityLiveRegion="polite" style={agentStyles.notice}>{t("savedToAccount")}</Text> : null}
        </AgentSection>
        <AgentSection title={t("agentRegionSection")}>
          <AgentCard padded={false}>
            <AgentNavRow divider={false} chevron={false} icon={Globe2} title={t("agentCountry")} subtitle={t("agentCountryValue")} />
            <AgentNavRow chevron={false} icon={Banknote} title={t("agentCurrency")} subtitle={t("agentCurrencyValue")} />
            <AgentNavRow chevron={false} icon={CalendarDays} title={t("agentDateFormat")} subtitle={date(now.toISOString())} />
            <AgentNavRow chevron={false} icon={Clock3} title={t("agentTimeFormat")} subtitle={`${t("agentTimeFormat24")} · ${timeExample}`} />
          </AgentCard>
        </AgentSection>
        {/* TimezonePicker carries its own "Time zone" title — no second heading. */}
        <TimezonePicker />
        <AgentCard padded={false}>
          <AgentNavRow divider={false} icon={Bell} title={t("langNotifPrefs")} subtitle={t("langChannelsBody")} onPress={() => router.push("/account/notifications")} />
        </AgentCard>
      </AgentShell>
    );
  }
  return (
    <Screen
      footer={
        <CtaBar>
          <Button label={t("saveLanguage")} loading={busy} disabled={value === current} onPress={() => void save()} />
        </CtaBar>
      }
    >
      <BrandHeader title={t("langPageTitle")} subtitle={t("langPageSubtitle")} back />
      <SectionHeading title={t("language")} icon={Languages} />
      <Card style={styles.card}>
        <View style={styles.segment} accessibilityRole="radiogroup">
          {(
            [
              ["en", "English"],
              ["fr", "Français"],
            ] as const
          ).map(([code, label]) => {
            const on = value === code;
            const Icon = on ? CheckCircle2 : Circle;
            return (
              <Pressable
                key={code}
                accessibilityRole="radio"
                accessibilityState={{ checked: on }}
                accessibilityLabel={label}
                onPress={() => setValue(code)}
                style={[styles.option, on && styles.optionOn]}
              >
                <Text style={[styles.optionText, on && styles.optionTextOn]}>{label}</Text>
                <Icon size={22} color={on ? colors.white : colors.neutral500} />
              </Pressable>
            );
          })}
        </View>
      </Card>
      {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}
      {saved ? <Text accessibilityLiveRegion="polite" style={styles.notice}>{t("savedToAccount")}</Text> : null}
      <Banner icon={Bell} tint="blue" title={t("langNotifPrefs")} body={t("langChannelsBody")} onPress={() => router.push("/account/notifications")} />
    </Screen>
  );
}
const agentStyles = StyleSheet.create({
  lead: { ...AT.body, color: ac.secondary },
  segment: { flexDirection: "row", padding: 4, gap: 4, borderRadius: AL.inputRadius, backgroundColor: ac.surface, borderWidth: 1, borderColor: ac.border },
  segItem: { flex: 1, minHeight: 48, borderRadius: 11, alignItems: "center", justifyContent: "center" },
  segOn: { backgroundColor: ac.actionBlue },
  segText: { ...AT.button, color: ac.navy },
  segTextOn: { color: ac.surface },
  error: { ...AT.secondary, color: ac.danger },
  notice: { ...AT.secondary, color: ac.success },
});
const styles = StyleSheet.create({
  card: { borderRadius: radius.feature, padding: space.x2 },
  segment: { flexDirection: "row", gap: space.x2 },
  option: { flex: 1, minHeight: 48, borderRadius: radius.control, backgroundColor: colors.neutral100, flexDirection: "row", alignItems: "center", justifyContent: "space-between", paddingHorizontal: space.x4 },
  optionOn: { backgroundColor: colors.blue600 },
  optionText: { ...type.label, color: colors.navy950 },
  optionTextOn: { color: colors.white },
  error: { ...type.meta, color: colors.dangerText },
  notice: { ...type.meta, color: colors.successText },
});
