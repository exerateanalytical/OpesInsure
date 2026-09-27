import React, { useCallback, useEffect, useMemo, useState } from "react";
import { ActivityIndicator, Modal, Pressable, SectionList, StyleSheet, Text, TextInput, useWindowDimensions, View } from "react-native";
import { useSafeAreaInsets } from "react-native-safe-area-context";
import { Check, ChevronDown, Search, X } from "lucide-react-native";
import { Button, FIELD, fieldStyles } from "@/components/ui";
import { useTranslation } from "@/i18n";
import { loadList, suggestValue, type MasterList } from "@/lib/masterData";
import { groupByParent, labelOf, narrowByParent, OTHER, parseList, searchMasterValues, type MasterValue } from "@/lib/masterFields";
import { colors, radius, space, type } from "@/theme/tokens";

type Props = {
  label: string;
  domain: string;
  list: string;
  value?: string;
  /** Multi-select stores a JSON array string. */
  multiple?: boolean;
  /** Code of the selected parent (parent_field) or a fixed parent code. */
  parent?: string;
  otherAllowed?: boolean;
  otherText?: string;
  /** label: display label of the pick (the typed text for Other) — used to compose text targets. */
  onChange: (value: string, otherText?: string, label?: string) => void;
  error?: string;
  required?: boolean;
  lineCode?: string;
  fieldKey?: string;
  /** Suggestion context (quote.risk, form.customer_profile, …). */
  screen?: string;
  /** Values from somewhere other than master data (endpoint pickers, timezones). "Other" is appended when otherAllowed. */
  loader?: () => Promise<MasterValue[]>;
  /** Re-load when this changes (e.g. the endpoint URL). */
  loaderKey?: string;
  /** false: an Other pick is not filed as a master-data suggestion (no list to file it under). */
  suggest?: boolean;
  /** Custom text for the empty-selection placeholder. */
  placeholder?: string;
};

/**
 * Searchable controlled-list picker (institutional master data). EN/FR labels
 * follow the app language; results are grouped by parent when the list is
 * hierarchical and narrowed when a parent is chosen. "Other / Not listed"
 * asks for the value, files it for review and never blocks the quote.
 */
export function MasterSelectField({ label, domain, list, value, multiple, parent, otherAllowed, otherText, onChange, error, required, lineCode, fieldKey, screen, loader, loaderKey, suggest = true, placeholder }: Props) {
  const { t, language } = useTranslation();
  const lang = language === "fr" ? "fr" : "en";
  const [data, setData] = useState<{ list: MasterList; parents?: MasterList } | null>(null);
  const [state, setState] = useState<"idle" | "loading" | "error">("idle");
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState("");
  const [otherMode, setOtherMode] = useState(false);
  const [draft, setDraft] = useState(otherText ?? "");
  const insets = useSafeAreaInsets();
  const { height } = useWindowDimensions();

  const load = useCallback(async () => {
    setState("loading");
    try {
      if (loader) {
        const rows = await loader();
        const other: MasterValue[] = otherAllowed ? [{ code: OTHER, label: { en: "Other / Not listed", fr: "Autre / Non répertorié" }, is_other: true }] : [];
        setData({ list: { code: list, label: { en: label, fr: label }, values: [...rows, ...other] } });
      } else setData(await loadList(domain, list));
      setState("idle");
    } catch {
      setState("error");
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- loader identity changes every render; loaderKey tracks it.
  }, [domain, list, loaderKey, otherAllowed]);
  useEffect(() => {
    void load();
  }, [load]);

  const selected = multiple ? parseList(value) : value ? [value] : [];
  const values = useMemo(() => {
    const all = data?.list.values ?? [];
    const narrowed = narrowByParent(all, parent);
    return searchMasterValues(otherAllowed === false ? narrowed.filter((v) => !v.is_other) : narrowed, q, lang);
  }, [data, parent, q, lang, otherAllowed]);
  const sections = useMemo(() => (q || parent ? [{ title: "", data: values }] : groupByParent(values, data?.parents?.values, lang)), [values, data, q, parent, lang]);
  const byCode = useMemo(() => new Map((data?.list.values ?? []).map((v) => [v.code, v])), [data]);
  const display = selected.map((c) => (c === OTHER ? `${t("mdOther")}${otherText ? `: ${otherText}` : ""}` : byCode.get(c) ? labelOf(byCode.get(c)!, lang) : c)).join(", ");
  const fieldLabel = required ? label : `${label} ${t("mdOptional")}`;

  const pick = (v: MasterValue) => {
    if (v.is_other || v.code === OTHER) {
      setOtherMode(true);
      return;
    }
    if (multiple) {
      const next = selected.includes(v.code) ? selected.filter((c) => c !== v.code) : [...selected, v.code];
      onChange(JSON.stringify(next), otherText);
    } else {
      onChange(v.code, undefined, labelOf(v, lang));
      setOpen(false);
      setQ("");
    }
  };

  const confirmOther = () => {
    const text = draft.trim();
    if (!text) return;
    const next = multiple ? JSON.stringify([...selected.filter((c) => c !== OTHER), OTHER]) : OTHER;
    onChange(next, text, text);
    // Filed for review now; for quotes the server files it again (de-duplicated) with the facts.
    if (suggest) void suggestValue({ domain, list, text, parent: parent || undefined, line_code: lineCode, field_key: fieldKey, screen });
    setOtherMode(false);
    setOpen(false);
    setQ("");
  };

  return (
    <View style={fieldStyles.field}>
      <Text style={fieldStyles.label}>{fieldLabel}</Text>
      <Pressable
        accessibilityRole="combobox"
        accessibilityLabel={`${label}: ${display || t("mdNotChosen")}`}
        accessibilityState={{ expanded: open, busy: state === "loading" }}
        onPress={() => (state === "error" ? void load() : setOpen(true))}
        style={({ pressed }) => [fieldStyles.control, pressed && fieldStyles.controlPressed, error ? fieldStyles.controlError : null]}
      >
        <Text style={[fieldStyles.value, !display && fieldStyles.placeholder, state === "error" && s.loadError]} numberOfLines={2}>
          {state === "error" ? t("mdLoadFailed") : display || placeholder || (multiple ? t("mdChooseSeveral") : t("mdChoose"))}
        </Text>
        {state === "loading" ? <ActivityIndicator size="small" color={colors.blue600} /> : <ChevronDown size={20} color={colors.neutral600} />}
      </Pressable>
      {error ? <Text accessibilityRole="alert" accessibilityLiveRegion="polite" style={fieldStyles.error}>{error}</Text> : null}
      <Modal visible={open} transparent animationType="slide" statusBarTranslucent onRequestClose={() => setOpen(false)}>
        <View style={s.root}>
        <Pressable style={s.backdrop} onPress={() => setOpen(false)} accessibilityRole="button" accessibilityLabel={t("mdClose")} />
        <View style={[s.sheet, { maxHeight: Math.round(height * 0.85), paddingBottom: Math.max(insets.bottom, space.x4) }]} accessibilityViewIsModal>
          <View style={s.handle} accessibilityElementsHidden importantForAccessibility="no-hide-descendants" />
          <View style={s.sheetHeader}>
            <Text accessibilityRole="header" style={s.sheetTitle}>{label}</Text>
            <Pressable accessibilityRole="button" accessibilityLabel={t("mdClose")} onPress={() => setOpen(false)} hitSlop={8} style={s.closeBtn}>
              <X size={20} color={colors.neutral600} />
            </Pressable>
          </View>
          {otherMode ? (
            <View style={s.gap}>
              <Text style={s.hint}>{t("mdOtherHint")}</Text>
              <TextInput
                autoFocus
                value={draft}
                onChangeText={setDraft}
                placeholder={t("mdOtherPlaceholder")}
                placeholderTextColor={colors.neutral500}
                style={s.input}
                maxLength={200}
                accessibilityLabel={t("mdOtherPlaceholder")}
              />
              <Button label={t("mdUseThisValue")} disabled={!draft.trim()} onPress={confirmOther} />
              <Button label={t("mdBackToList")} variant="tertiary" onPress={() => setOtherMode(false)} />
            </View>
          ) : (
            <>
              <View style={s.search}>
                <Search size={18} color={colors.neutral500} />
                <TextInput value={q} onChangeText={setQ} placeholder={t("mdSearch")} placeholderTextColor={colors.neutral500} style={s.searchInput} autoCorrect={false} accessibilityLabel={t("mdSearch")} />
              </View>
              {state === "loading" && !data ? <ActivityIndicator color={colors.blue600} /> : null}
              <SectionList
                style={s.list}
                sections={sections}
                keyExtractor={(v) => v.code}
                keyboardShouldPersistTaps="handled"
                ListEmptyComponent={<Text style={s.hint}>{t("mdNoMatch")}</Text>}
                renderSectionHeader={({ section }) => (section.title ? <Text style={s.group}>{section.title}</Text> : null)}
                renderItem={({ item }) => {
                  const on = selected.includes(item.code);
                  const isOther = item.is_other || item.code === OTHER;
                  return (
                    <Pressable accessibilityRole={multiple ? "checkbox" : "radio"} accessibilityState={{ selected: on, checked: on }} style={[s.option, on && s.optionOn]} onPress={() => pick(item)}>
                      <Text style={[s.optionText, isOther && s.otherText]}>{isOther ? t("mdOther") : labelOf(item, lang)}</Text>
                      {on ? <Check size={18} color={colors.blue600} /> : null}
                    </Pressable>
                  );
                }}
              />
              {multiple ? <Button label={t("mdDone")} onPress={() => setOpen(false)} /> : null}
            </>
          )}
        </View>
        </View>
      </Modal>
    </View>
  );
}

const s = StyleSheet.create({
  loadError: { color: colors.dangerText },
  root: { flex: 1, justifyContent: "flex-end" },
  backdrop: { ...StyleSheet.absoluteFillObject, backgroundColor: "rgba(11,31,78,0.45)" },
  sheet: { width: "100%", maxWidth: 720, alignSelf: "center", backgroundColor: colors.white, borderTopLeftRadius: radius.sheet, borderTopRightRadius: radius.sheet, paddingHorizontal: space.x4, paddingTop: space.x2, gap: space.x3 },
  handle: { alignSelf: "center", width: 40, height: 4, borderRadius: 2, backgroundColor: colors.neutral300 },
  sheetHeader: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", minHeight: 44 },
  sheetTitle: { ...type.cardTitle, color: colors.navy950, flex: 1 },
  closeBtn: { width: 44, height: 44, alignItems: "center", justifyContent: "center", marginRight: -space.x2 },
  search: { flexDirection: "row", alignItems: "center", gap: space.x2, borderWidth: 1, borderColor: colors.neutral300, borderRadius: FIELD.radius, paddingHorizontal: space.x3, minHeight: 48, backgroundColor: colors.neutral50 },
  searchInput: { ...type.body, flex: 1, color: colors.navy950, paddingVertical: 0, minHeight: 46 },
  input: { ...type.body, minHeight: FIELD.height, borderWidth: 1, borderColor: colors.neutral300, borderRadius: FIELD.radius, paddingHorizontal: FIELD.padX, color: colors.navy950 },
  list: { flexGrow: 0, flexShrink: 1 },
  group: { ...type.caption, color: colors.neutral600, paddingTop: space.x2, paddingBottom: space.x1, backgroundColor: colors.white },
  option: { minHeight: 52, flexDirection: "row", alignItems: "center", justifyContent: "space-between", gap: space.x3, paddingHorizontal: space.x3, paddingVertical: space.x2, borderRadius: FIELD.radius },
  optionOn: { backgroundColor: colors.blue50 },
  optionText: { ...type.body, color: colors.navy950, flex: 1 },
  otherText: { color: colors.blue600 },
  hint: { ...type.meta, color: colors.neutral600 },
  gap: { gap: space.x2 },
});
