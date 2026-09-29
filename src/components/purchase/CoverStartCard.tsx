import React, { useEffect, useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { CalendarDays, ChevronLeft, ChevronRight } from "lucide-react-native";
import { RadioCard, SectionHeading } from "@/components/design";
import { Button, Card } from "@/components/ui";
import { ProposalLifecycleApi, type CoverTermRule, type CoverTerms } from "@/api/workflow";
import { addDays, clampStart, doualaToday } from "@/lib/coverStart";
import { useFormatters } from "@/hooks/useFormatters";
import { useTranslation } from "@/i18n";
import { colors, space, type } from "@/theme/tokens";

/**
 * "When should your cover start?" while the application can still be edited: as soon as the policy is
 * issued (IMMEDIATE) or on a chosen date (SPECIFIED_DATE, today … today + max_advance_days). Saved with
 * PUT proposals/{p}/cover-terms; the policy is issued with this start and the product's duration.
 */
export function CoverStartCard({ proposalId, rule, terms, onSaved }: { proposalId: string; rule: CoverTermRule; terms?: CoverTerms | null; onSaved: () => void }) {
  const { t } = useTranslation();
  const f = useFormatters();
  const savedRule = terms?.effective_rule === "SPECIFIED_DATE" ? "SPECIFIED_DATE" : "IMMEDIATE";
  const [mode, setMode] = useState<"IMMEDIATE" | "SPECIFIED_DATE">(savedRule);
  const [date, setDate] = useState(() => clampStart(terms?.start_date ?? addDays(doualaToday(), 1), rule.max_advance_days));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  useEffect(() => setSaved(false), [mode, date]);

  const step = (n: number) => setDate((d) => clampStart(addDays(d, n), rule.max_advance_days));
  const changed = mode !== savedRule || (mode === "SPECIFIED_DATE" && date !== terms?.start_date);

  const save = async () => {
    setBusy(true);
    setError(null);
    try {
      await ProposalLifecycleApi.setCoverStart(proposalId, mode, mode === "SPECIFIED_DATE" ? date : undefined);
      setSaved(true);
      onSaved();
    } catch (e) {
      setError(e instanceof Error && e.message ? e.message : t("csFailed"));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card>
      <SectionHeading icon={CalendarDays} title={t("csTitle")} />
      <View style={s.options} accessibilityRole="radiogroup">
        <RadioCard selected={mode === "IMMEDIATE"} onPress={() => setMode("IMMEDIATE")} tint="blue" title={t("csImmediate")} subtitle={t("csImmediateBody")} />
        <RadioCard selected={mode === "SPECIFIED_DATE"} onPress={() => setMode("SPECIFIED_DATE")} tint="blue" title={t("csOnDate")} subtitle={t("csOnDateBody", { days: rule.max_advance_days })} />
      </View>
      {mode === "SPECIFIED_DATE" ? (
        <View style={s.stepper}>
          <Pressable accessibilityRole="button" accessibilityLabel={t("csEarlier")} hitSlop={6} onPress={() => step(-1)} style={({ pressed }) => [s.arrow, pressed && s.pressed]}>
            <ChevronLeft size={22} color={colors.blue600} />
          </Pressable>
          <Text style={s.date} accessibilityLiveRegion="polite">{f.date(`${date}T12:00:00`)}</Text>
          <Pressable accessibilityRole="button" accessibilityLabel={t("csLater")} hitSlop={6} onPress={() => step(1)} style={({ pressed }) => [s.arrow, pressed && s.pressed]}>
            <ChevronRight size={22} color={colors.blue600} />
          </Pressable>
        </View>
      ) : null}
      {mode === "SPECIFIED_DATE" ? (
        <View style={s.quick}>
          <Button label={t("csPlusWeek")} variant="tertiary" onPress={() => step(7)} />
          <Button label={t("csPlusMonth")} variant="tertiary" onPress={() => step(30)} />
        </View>
      ) : null}
      {changed ? <Button label={t("csSave")} loading={busy} disabled={busy} onPress={() => void save()} /> : null}
      {saved ? <Text accessibilityLiveRegion="polite" style={s.ok}>{t("csSaved")}</Text> : null}
      {error ? <Text accessibilityRole="alert" style={s.error}>{error}</Text> : null}
    </Card>
  );
}

const s = StyleSheet.create({
  options: { gap: space.x2 },
  stepper: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  date: { ...type.label, fontSize: 17, color: colors.navy950, flex: 1, textAlign: "center" },
  quick: { flexDirection: "row", gap: space.x2 },
  arrow: { width: 48, height: 48, borderRadius: 24, borderWidth: 1, borderColor: colors.blue100, backgroundColor: colors.blue50, alignItems: "center", justifyContent: "center" },
  pressed: { opacity: 0.8 },
  ok: { ...type.meta, color: colors.successText },
  error: { ...type.meta, color: colors.dangerText },
});
