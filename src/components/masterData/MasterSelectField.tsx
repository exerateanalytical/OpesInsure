import React, { useCallback, useEffect, useMemo, useState } from "react";
import { ActivityIndicator, Modal, Pressable, SectionList, StyleSheet, Text, TextInput, View } from "react-native";
import { Check, ChevronDown, Search, X } from "lucide-react-native";
import { Button } from "@/components/ui";
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
  onChange: (value: string, otherText?: string) => void;
  error?: string;
  required?: boolean;
  lineCode?: string;
  fieldKey?: string;
};

/**
 * Searchable controlled-list picker (institutional master data). EN/FR labels
 * follow the app language; results are grouped by parent when the list is
 * hierarchical and narrowed when a parent is chosen. "Other / Not listed"
 * asks for the value, files it for review and never blocks the quote.
 */
export function MasterSelectField({ label, domain, list, value, multiple, parent, otherAllowed, otherText, onChange, error, required, lineCode, fieldKey }: Props) {
  const { t, language } = useTranslation();
  const lang = language === "fr" ? "fr" : "en";
  const [data, setData] = useState<{ list: MasterList; parents?: MasterList } | null>(null);
  const [state, setState] = useState<"idle" | "loading" | "error">("idle");
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState("");
  const [otherMode, setOtherMode] = useState(false);
  const [draft, setDraft] = useState(otherText ?? "");

  const load = useCallback(async () => {
    setState("loading");
    try {
      setData(await loadList(domain, list));
      setState("idle");
    } catch {
      setState("error");
    }
  }, [domain, list]);
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
      onChange(v.code);
      setOpen(false);
      setQ("");
    }
  };

  const confirmOther = () => {
    const text = draft.trim();
    if (!text) return;
    const next = multiple ? JSON.stringify([...selected.filter((c) => c !== OTHER), OTHER]) : OTHER;
    onChange(next, text);
    // Filed for review now; the server files it again (de-duplicated) with the quote.
    void suggestValue({ domain, list, text, parent: parent || undefined, line_code: lineCode, field_key: fieldKey });
    setOtherMode(false);
    setOpen(false);
    setQ("");
  };

  return (
    <View style={s.field}>
      <Text style={s.label}>{fieldLabel}</Text>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={`${label}: ${display || t("mdNotChosen")}`}
        onPress={() => (state === "error" ? void load() : setOpen(true))}
        style={[s.select, error ? s.selectError : null]}
      >
        {state === "loading" ? <ActivityIndicator color={colors.blue600} /> : null}
        <Text style={[s.selectText, !display && s.placeholder]} numberOfLines={2}>
          {state === "error" ? t("mdLoadFailed") : display || (multiple ? t("mdChooseSeveral") : t("mdChoose"))}
        </Text>
        <ChevronDown size={18} color={colors.neutral500} />
      </Pressable>
      {error ? <Text accessibilityRole="alert" style={s.error}>{error}</Text> : null}
      <Modal visible={open} transparent animationType="slide" onRequestClose={() => setOpen(false)}>
        <Pressable style={s.backdrop} onPress={() => setOpen(false)} accessibilityRole="button" accessibilityLabel={t("mdClose")} />
        <View style={s.sheet}>
          <View style={s.sheetHeader}>
            <Text style={s.sheetTitle}>{label}</Text>
            <Pressable accessibilityRole="button" accessibilityLabel={t("mdClose")} onPress={() => setOpen(false)}>
              <X size={20} color={colors.neutral500} />
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
      </Modal>
    </View>
  );
}

const s = StyleSheet.create({
  field: { gap: space.x1 },
  label: { ...type.label, color: colors.navy950 },
  select: { minHeight: 48, borderWidth: 1, borderColor: colors.neutral300, borderRadius: radius.control, paddingHorizontal: space.x3, flexDirection: "row", alignItems: "center", gap: space.x2, backgroundColor: colors.white },
  selectError: { borderColor: colors.danger },
  selectText: { ...type.body, color: colors.navy950, flex: 1 },
  placeholder: { color: colors.neutral400 },
  error: { ...type.meta, color: colors.dangerText },
  backdrop: { flex: 1, backgroundColor: "rgba(7,26,43,0.4)" },
  sheet: { maxHeight: "80%", backgroundColor: colors.white, borderTopLeftRadius: radius.sheet, borderTopRightRadius: radius.sheet, padding: space.x4, gap: space.x2 },
  sheetHeader: { flexDirection: "row", alignItems: "center", justifyContent: "space-between" },
  sheetTitle: { ...type.cardTitle, color: colors.navy950, flex: 1 },
  search: { flexDirection: "row", alignItems: "center", gap: space.x2, borderWidth: 1, borderColor: colors.neutral300, borderRadius: radius.control, paddingHorizontal: space.x3, minHeight: 44 },
  searchInput: { ...type.body, flex: 1, color: colors.navy950 },
  input: { ...type.body, minHeight: 48, borderWidth: 1, borderColor: colors.neutral300, borderRadius: radius.control, paddingHorizontal: space.x3, color: colors.navy950 },
  group: { ...type.caption, color: colors.neutral600, paddingTop: space.x2, paddingBottom: space.x1, backgroundColor: colors.white },
  option: { minHeight: 48, flexDirection: "row", alignItems: "center", justifyContent: "space-between", paddingHorizontal: space.x3, borderRadius: radius.control },
  optionOn: { backgroundColor: colors.blue50 },
  optionText: { ...type.body, color: colors.navy950, flex: 1 },
  otherText: { color: colors.blue600 },
  hint: { ...type.meta, color: colors.neutral600 },
  gap: { gap: space.x2 },
});
