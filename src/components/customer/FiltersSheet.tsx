import React, { useEffect, useState } from "react";
import { Modal, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";
import { ArrowLeft, ArrowRight, Check, LucideIcon } from "lucide-react-native";
import { BrandLockup } from "@/components/design";
import { InstitutionMark } from "@/components/InstitutionMark";
import { CONTENT_MAX_WIDTH, ripple } from "@/components/ui";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";
import { activeFilterCount as coreActiveCount, customPeriod, emptyFilters as coreEmpty } from "@/components/filters/core";

export type FilterOption = {
  value: string;
  label: string;
  icon?: LucideIcon;
  /** Provider options: rendered through InstitutionMark. */
  logoUrl?: string | null;
  initials?: string | null;
};
export type FilterSection = {
  key: string;
  title: string;
  subtitle?: string;
  options: FilterOption[];
  /** Exactly one value (sort order); otherwise any number, none = no filter. */
  single?: boolean;
  /** "period": single choice of date presets plus a custom from/to range (values from periodSection()). */
  kind?: "period" | "sort";
};
export type FilterValues = Record<string, string[]>;

/** Number of active choices across sections, ignoring single-choice sections at their default. */
export const activeFilterCount: (values: FilterValues, sections: FilterSection[]) => number = coreActiveCount;

/** Empty selection for every section (single-choice sections go back to their first option). */
export const emptyFilters: (sections: FilterSection[]) => FilterValues = coreEmpty;

/**
 * Filter sheet from opesinsure_filters_mobile_ui.png: full-screen modal with
 * option tiles per section, "Reset All" in the header and a pinned bar with
 * the live result count and "Show Results". Callers only pass sections the
 * loaded data can honour; `count` is evaluated on the draft selection.
 */
export function FiltersSheet({
  visible,
  onClose,
  sections,
  value,
  onApply,
  count,
  subtitle,
  header,
  footer,
}: {
  visible: boolean;
  onClose: () => void;
  sections: FilterSection[];
  value: FilterValues;
  onApply: (v: FilterValues) => void;
  count: (v: FilterValues) => number;
  subtitle?: string;
  /** Rendered under the title (saved / recent filters). */
  header?: React.ReactNode;
  /** Rendered after the sections (inputs the option tiles cannot express, e.g. amount ranges). */
  footer?: React.ReactNode;
}) {
  const { t } = useTranslation();
  const [draft, setDraft] = useState<FilterValues>(value);
  useEffect(() => {
    if (visible) setDraft(value);
  }, [visible, value]);
  const toggle = (s: FilterSection, v: string) =>
    setDraft((d) => {
      const cur = d[s.key] ?? [];
      if (s.kind === "period" && v === "custom") return { ...d, [s.key]: [customPeriod("", "")] };
      if (s.single) return { ...d, [s.key]: [v] };
      return { ...d, [s.key]: cur.includes(v) ? cur.filter((x) => x !== v) : [...cur, v] };
    });
  const n = count(draft);
  const visibleSections = sections.filter((s) => s.options.length > (s.single ? 1 : 0));
  return (
    <Modal visible={visible} animationType="slide" onRequestClose={onClose} presentationStyle="fullScreen">
      <SafeAreaView style={st.safe} edges={["top", "bottom"]}>
        <View style={st.top}>
          <Pressable accessibilityRole="button" accessibilityLabel={t("back")} onPress={onClose} hitSlop={6} style={({ pressed }) => [st.iconBtn, pressed && st.pressed]}>
            <ArrowLeft size={22} color={colors.navy900} />
          </Pressable>
          <BrandLockup />
          <Pressable accessibilityRole="button" onPress={() => setDraft(emptyFilters(sections))} style={({ pressed }) => [st.reset, pressed && st.pressed]}>
            <Text style={st.resetText}>{t("filtersReset")}</Text>
          </Pressable>
        </View>
        <ScrollView style={st.scroll} contentContainerStyle={st.content}>
          <View style={st.head}>
            <Text accessibilityRole="header" style={st.title}>{t("filtersTitle")}</Text>
            <Text style={st.subtitle}>{subtitle ?? t("filtersSubtitle")}</Text>
          </View>
          {header}
          {visibleSections.map((s) => (
            <View key={s.key} style={st.card}>
              <Text accessibilityRole="header" style={st.cardTitle}>{s.title}</Text>
              {s.subtitle ? <Text style={st.cardSub}>{s.subtitle}</Text> : null}
              <View style={st.options} accessibilityRole={s.single ? "radiogroup" : undefined}>
                {s.options.map((o) => {
                  const on = (draft[s.key] ?? []).includes(o.value) || (o.value === "custom" && (draft[s.key]?.[0] ?? "").startsWith("custom:") && !s.options.some((x) => x.value === draft[s.key]?.[0]));
                  const Icon = o.icon;
                  const provider = o.logoUrl !== undefined || o.initials;
                  return (
                    <Pressable
                      key={o.value}
                      accessibilityRole={s.single ? "radio" : "checkbox"}
                      accessibilityState={s.single ? { selected: on } : { checked: on }}
                      accessibilityLabel={o.label}
                      onPress={() => toggle(s, o.value)}
                      android_ripple={ripple()}
                      style={({ pressed }) => [st.option, on && st.optionOn, pressed && st.pressed]}
                    >
                      {provider ? (
                        <InstitutionMark logoUrl={o.logoUrl} initials={o.initials} size={32} />
                      ) : Icon ? (
                        <Icon size={22} color={on ? colors.blue600 : colors.navy900} />
                      ) : null}
                      <Text style={[st.optionText, on && st.optionTextOn]}>{o.label}</Text>
                      {on ? (
                        <View style={st.check}>
                          <Check size={12} color={colors.white} strokeWidth={3} />
                        </View>
                      ) : null}
                    </Pressable>
                  );
                })}
              </View>
              {s.kind === "period" ? <CustomRange value={draft[s.key]?.[0]} onChange={(v) => setDraft((d) => ({ ...d, [s.key]: [v] }))} /> : null}
            </View>
          ))}
          {footer}
        </ScrollView>
        <View style={st.bar}>
          <View style={st.countRow} accessibilityLiveRegion="polite">
            <Text style={st.count}>{n}</Text>
            <Text style={st.countText}>{t("filtersFound")}</Text>
          </View>
          <Pressable
            accessibilityRole="button"
            onPress={() => {
              onApply(draft);
              onClose();
            }}
            android_ripple={ripple(true)}
            style={({ pressed }) => [st.apply, pressed && st.pressed]}
          >
            <Text style={st.applyText}>{t("filtersShowResults")}</Text>
            <ArrowRight size={20} color={colors.white} />
          </Pressable>
        </View>
      </SafeAreaView>
    </Modal>
  );
}

const DAY = /^\d{4}-\d{2}-\d{2}$/;
/** From / To (YYYY-MM-DD, inclusive local days) for a custom period. */
function CustomRange({ value, onChange }: { value?: string; onChange: (v: string) => void }) {
  const { t } = useTranslation();
  const [from, to] = (value?.startsWith("custom:") ? value.slice(7) : "..").split("..");
  if (!value?.startsWith("custom:")) return null;
  const field = (label: string, v: string, set: (x: string) => void) => (
    <View style={st.rangeField}>
      <Text style={st.cardSub}>{label}</Text>
      <TextInput
        accessibilityLabel={label}
        value={v}
        onChangeText={(x) => set(x.replace(/[^0-9-]/g, "").slice(0, 10))}
        placeholder="YYYY-MM-DD"
        placeholderTextColor={colors.neutral500}
        keyboardType="numbers-and-punctuation"
        style={[st.rangeInput, v && !DAY.test(v) ? st.rangeBad : null]}
      />
    </View>
  );
  return (
    <View style={st.range}>
      {field(t("fltFrom"), from ?? "", (x) => onChange(customPeriod(x, to ?? "")))}
      {field(t("fltTo"), to ?? "", (x) => onChange(customPeriod(from ?? "", x)))}
    </View>
  );
}

const st = StyleSheet.create({
  range: { flexDirection: "row", gap: space.x3, marginTop: space.x3, flexWrap: "wrap" },
  rangeField: { flex: 1, minWidth: 130, gap: 4 },
  rangeInput: { ...type.body, minHeight: 48, borderWidth: 1, borderColor: colors.neutral300, borderRadius: radius.control, paddingHorizontal: space.x3, color: colors.navy950, backgroundColor: colors.white },
  rangeBad: { borderColor: colors.danger },
  safe: { flex: 1, height: "100%", backgroundColor: colors.neutral50 },
  scroll: { flex: 1 },
  pressed: { opacity: 0.85 },
  top: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", paddingHorizontal: space.x5, paddingTop: space.x2, minHeight: 56, width: "100%", maxWidth: CONTENT_MAX_WIDTH, alignSelf: "center" },
  iconBtn: { width: 48, height: 48, borderRadius: 24, borderWidth: 1, borderColor: colors.neutral200, backgroundColor: colors.white, alignItems: "center", justifyContent: "center" },
  reset: { minHeight: 48, paddingHorizontal: space.x2, justifyContent: "center" },
  resetText: { ...type.label, color: colors.blue600 },
  content: { paddingHorizontal: space.x5, paddingBottom: space.x8, gap: space.x3, width: "100%", maxWidth: CONTENT_MAX_WIDTH, alignSelf: "center" },
  head: { gap: 4, marginTop: space.x3, marginBottom: space.x2 },
  title: { ...type.pageTitle, color: colors.navy950 },
  subtitle: { ...type.body, color: colors.neutral600 },
  card: { backgroundColor: colors.white, borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.card, padding: space.x4, gap: 4 },
  cardTitle: { ...type.cardTitle, color: colors.navy950 },
  cardSub: { ...type.meta, color: colors.neutral600 },
  options: { flexDirection: "row", flexWrap: "wrap", gap: space.x2, marginTop: space.x3 },
  option: { minHeight: 52, flexDirection: "row", alignItems: "center", gap: space.x2, paddingHorizontal: space.x3, paddingVertical: space.x2, borderRadius: radius.card, borderWidth: 1, borderColor: colors.neutral200, backgroundColor: colors.white, maxWidth: "100%" },
  optionOn: { borderColor: colors.blue600, backgroundColor: colors.blue50 },
  optionText: { ...type.label, color: colors.navy950, flexShrink: 1 },
  optionTextOn: { color: colors.blue700 },
  check: { width: 20, height: 20, borderRadius: 10, backgroundColor: colors.blue600, alignItems: "center", justifyContent: "center" },
  bar: { flexDirection: "row", alignItems: "center", gap: space.x4, paddingHorizontal: space.x5, paddingVertical: space.x3, borderTopWidth: 1, borderTopColor: colors.neutral200, backgroundColor: colors.white },
  countRow: { flex: 1, flexDirection: "row", alignItems: "center", gap: space.x2 },
  count: { fontFamily: "Inter_700Bold", fontSize: 28, lineHeight: 34, color: colors.navy950 },
  countText: { ...type.meta, color: colors.neutral600, flexShrink: 1 },
  apply: { minWidth: 168, paddingHorizontal: space.x4, minHeight: 52, borderRadius: radius.control, backgroundColor: colors.blue600, flexDirection: "row", alignItems: "center", justifyContent: "center", gap: space.x2, overflow: "hidden" },
  applyText: { ...type.label, fontSize: 16, color: colors.white },
});
