import React, { ReactNode, useMemo, useState } from "react";
import { FlatList, Modal, Pressable, StyleSheet, Text, View } from "react-native";
import { Check, ChevronDown, CircleAlert, X } from "lucide-react-native";
import { Button, Card, StatusChip } from "@/components/ui";
import { colors, radius, space, type } from "@/theme/tokens";
import { isValidIsoDate, RiskOption } from "@/lib/riskSchema";
import { errorMessage, Tone } from "@/lib/purchase";
import { useTranslation } from "@/i18n";

export function InfoRow({ label, value, strong }: { label: string; value: ReactNode; strong?: boolean }) {
  return (
    <View style={s.infoRow}>
      <Text style={s.infoLabel}>{label}</Text>
      {typeof value === "string" || typeof value === "number" ? (
        <Text style={[s.infoValue, strong && s.strong]}>{value}</Text>
      ) : (
        value
      )}
    </View>
  );
}

export function Rule() {
  return <View style={s.rule} />;
}

export function ErrorCard({ error, fallback, onRetry, retryLabel }: { error: unknown; fallback: string; onRetry?: () => void; retryLabel?: string }) {
  const { t } = useTranslation();
  return (
    <Card>
      <View style={s.inline}>
        <CircleAlert size={20} color={colors.dangerText} />
        <Text accessibilityRole="alert" style={s.error}>
          {errorMessage(error, fallback)}
        </Text>
      </View>
      {onRetry ? <Button label={retryLabel ?? t("tryAgain")} variant="secondary" onPress={onRetry} /> : null}
    </Card>
  );
}

export function LoadMore({ hasMore, loading, error, onPress }: { hasMore: boolean; loading: boolean; error?: unknown; onPress: () => void }) {
  const { t } = useTranslation();
  if (!hasMore) return null;
  return (
    <View style={s.gap}>
      {error ? <Text style={s.error}>{errorMessage(error, t("loadMoreFailed"))}</Text> : null}
      <Button label={error ? t("loadMoreRetry") : t("loadMore")} variant="secondary" loading={loading} onPress={onPress} />
    </View>
  );
}

/** Horizontal progress stepper (Initiated → Awaiting → Confirmed, wizard steps…). */
export function Stepper({ steps, current, failed, done }: { steps: string[]; current: number; failed?: boolean; done?: boolean }) {
  const { t } = useTranslation();
  return (
    <View style={s.stepper} accessibilityRole="progressbar" accessibilityLabel={t("stepOf", { current: current + 1, total: steps.length, label: steps[current] ?? "" })}>
      {steps.map((label, i) => {
        const complete = i < current || (done && i === current);
        const active = i === current && !done;
        const bad = failed && i === current;
        return (
          <View key={label} style={s.step}>
            <View style={[s.dot, complete && s.dotDone, active && s.dotActive, bad && s.dotFailed]}>
              {complete ? <Check size={14} color={colors.white} /> : bad ? <X size={14} color={colors.white} /> : <Text style={[s.dotText, active && s.dotTextActive]}>{i + 1}</Text>}
            </View>
            <Text style={[s.stepLabel, (active || complete) && s.stepLabelOn]} numberOfLines={2}>
              {label}
            </Text>
          </View>
        );
      })}
    </View>
  );
}

export function ToneChip({ label, tone }: { label: string; tone: Tone }) {
  return <StatusChip label={label} tone={tone} />;
}

/** Tappable select that opens a modal list — used for every enum field. */
export function PickerField({ label, value, options, onChange, error, placeholder }: { label: string; value: string | undefined; options: RiskOption[]; onChange: (v: string) => void; error?: string; placeholder?: string }) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  const current = options.find((o) => o.value === value);
  return (
    <View style={s.field}>
      <Text style={s.fieldLabel}>{label}</Text>
      <Pressable accessibilityRole="button" accessibilityLabel={`${label}: ${current?.label ?? t("notChosen")}`} onPress={() => setOpen(true)} style={[s.select, error ? s.selectError : null]}>
        <Text style={[s.selectText, !current && s.placeholder]}>{current?.label ?? placeholder ?? t("chooseOption")}</Text>
        <ChevronDown size={18} color={colors.neutral500} />
      </Pressable>
      {error ? <Text accessibilityRole="alert" style={s.error}>{error}</Text> : null}
      <Modal visible={open} transparent animationType="slide" onRequestClose={() => setOpen(false)}>
        <Pressable style={s.backdrop} onPress={() => setOpen(false)} accessibilityRole="button" accessibilityLabel={t("close")} />
        <View style={s.sheet}>
          <Text style={s.sheetTitle}>{label}</Text>
          <FlatList
            data={options}
            keyExtractor={(o) => o.value}
            renderItem={({ item }) => (
              <Pressable
                accessibilityRole="radio"
                accessibilityState={{ selected: item.value === value }}
                style={[s.option, item.value === value && s.optionOn]}
                onPress={() => {
                  onChange(item.value);
                  setOpen(false);
                }}
              >
                <Text style={s.optionText}>{item.label}</Text>
                {item.value === value ? <Check size={18} color={colors.blue600} /> : null}
              </Pressable>
            )}
          />
        </View>
      </Modal>
    </View>
  );
}

const monthNames = (language: string) => {
  try {
    const f = new Intl.DateTimeFormat(language === "fr" ? "fr-CM" : "en-CM", { month: "short", timeZone: "UTC" });
    return Array.from({ length: 12 }, (_, i) => f.format(new Date(Date.UTC(2000, i, 1))));
  } catch {
    return ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
  }
};
const pad = (n: number) => String(n).padStart(2, "0");

/** Day / month / year pickers producing an ISO yyyy-mm-dd string. */
export function DateField({ label, value, onChange, error, minYear, maxYear }: { label: string; value: string | undefined; onChange: (v: string) => void; error?: string; minYear?: number; maxYear?: number }) {
  const { t, language } = useTranslation();
  const now = new Date().getFullYear();
  const lo = minYear ?? now - 100;
  const hi = maxYear ?? now + 2;
  const [y, m, d] = (value ?? "").split("-");
  const years = useMemo(() => Array.from({ length: hi - lo + 1 }, (_, i) => String(hi - i)).map((v) => ({ value: v, label: v })), [lo, hi]);
  const months = monthNames(language).map((label, i) => ({ value: pad(i + 1), label }));
  const days = Array.from({ length: 31 }, (_, i) => ({ value: pad(i + 1), label: String(i + 1) }));
  const set = (part: "y" | "m" | "d", v: string) => {
    const next = { y: y ?? "", m: m ?? "", d: d ?? "", [part]: v };
    onChange(`${next.y}-${next.m}-${next.d}`);
  };
  const incomplete = !!value && !isValidIsoDate(value) && [y, m, d].some((x) => !x);
  return (
    <View style={s.field}>
      <Text style={s.fieldLabel}>{label}</Text>
      <View style={s.dateRow}>
        <View style={s.flex}><PickerField label={t("dateDay")} value={d || undefined} options={days} onChange={(v) => set("d", v)} placeholder={t("dateDay")} /></View>
        <View style={s.flex}><PickerField label={t("dateMonth")} value={m || undefined} options={months} onChange={(v) => set("m", v)} placeholder={t("dateMonth")} /></View>
        <View style={s.flex}><PickerField label={t("dateYear")} value={y || undefined} options={years} onChange={(v) => set("y", v)} placeholder={t("dateYear")} /></View>
      </View>
      {error && !incomplete ? <Text accessibilityRole="alert" style={s.error}>{error}</Text> : incomplete ? <Text style={s.hint}>{t("dateIncomplete")}</Text> : null}
    </View>
  );
}

export function YesNoField({ label, value, onChange, error }: { label: string; value: string | undefined; onChange: (v: string) => void; error?: string }) {
  const { t } = useTranslation();
  return (
    <View style={s.field}>
      <Text style={s.fieldLabel}>{label}</Text>
      <View style={s.dateRow}>
        {[["true", t("yes")], ["false", t("no")]].map(([v, l]) => (
          <Pressable key={v} accessibilityRole="radio" accessibilityLabel={`${label}: ${l}`} accessibilityState={{ selected: value === v }} onPress={() => onChange(v!)} style={[s.choice, value === v && s.optionOn]}>
            <Text style={s.optionText}>{l}</Text>
          </Pressable>
        ))}
      </View>
      {error ? <Text accessibilityRole="alert" style={s.error}>{error}</Text> : null}
    </View>
  );
}

export const purchaseStyles = StyleSheet.create({
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral700 },
  meta: { ...type.meta, color: colors.neutral600 },
  error: { ...type.meta, color: colors.dangerText },
  row: { flexDirection: "row", alignItems: "center", gap: space.x2, flexWrap: "wrap" },
  between: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", gap: space.x3 },
  link: { ...type.label, color: colors.blue600 },
});

const s = StyleSheet.create({
  infoRow: { flexDirection: "row", justifyContent: "space-between", alignItems: "flex-start", gap: space.x3 },
  infoLabel: { ...type.meta, color: colors.neutral600, flexShrink: 1 },
  infoValue: { ...type.body, color: colors.navy950, textAlign: "right", flexShrink: 1 },
  strong: { fontFamily: "Inter_700Bold" },
  rule: { height: 1, backgroundColor: colors.neutral200 },
  inline: { flexDirection: "row", gap: space.x2, alignItems: "flex-start" },
  error: { ...type.meta, color: colors.dangerText, flex: 1 },
  hint: { ...type.meta, color: colors.neutral600 },
  gap: { gap: space.x2 },
  stepper: { flexDirection: "row", gap: space.x2 },
  step: { flex: 1, alignItems: "center", gap: space.x1 },
  dot: { width: 28, height: 28, borderRadius: 14, borderWidth: 2, borderColor: colors.neutral300, alignItems: "center", justifyContent: "center", backgroundColor: colors.white },
  dotDone: { backgroundColor: colors.success, borderColor: colors.success },
  dotActive: { borderColor: colors.blue600 },
  dotFailed: { backgroundColor: colors.danger, borderColor: colors.danger },
  dotText: { ...type.caption, color: colors.neutral500 },
  dotTextActive: { color: colors.blue600 },
  stepLabel: { ...type.caption, color: colors.neutral500, textAlign: "center" },
  stepLabelOn: { color: colors.navy950 },
  field: { gap: space.x1 },
  fieldLabel: { ...type.label, color: colors.navy950 },
  select: { minHeight: 48, borderWidth: 1, borderColor: colors.neutral300, borderRadius: radius.control, paddingHorizontal: space.x3, flexDirection: "row", alignItems: "center", justifyContent: "space-between", backgroundColor: colors.white },
  selectError: { borderColor: colors.danger },
  selectText: { ...type.body, color: colors.navy950, flex: 1 },
  placeholder: { color: colors.neutral400 },
  backdrop: { flex: 1, backgroundColor: "rgba(7,26,43,0.4)" },
  sheet: { maxHeight: "60%", backgroundColor: colors.white, borderTopLeftRadius: radius.sheet, borderTopRightRadius: radius.sheet, padding: space.x4, gap: space.x2 },
  sheetTitle: { ...type.cardTitle, color: colors.navy950 },
  option: { minHeight: 48, flexDirection: "row", alignItems: "center", justifyContent: "space-between", paddingHorizontal: space.x3, borderRadius: radius.control },
  optionOn: { backgroundColor: colors.blue50, borderColor: colors.blue600 },
  optionText: { ...type.body, color: colors.navy950 },
  dateRow: { flexDirection: "row", gap: space.x2 },
  flex: { flex: 1 },
  choice: { flex: 1, minHeight: 48, borderWidth: 1, borderColor: colors.neutral300, borderRadius: radius.control, alignItems: "center", justifyContent: "center" },
});
