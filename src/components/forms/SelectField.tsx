import React, { useEffect, useMemo, useState } from "react";
import { ActivityIndicator, FlatList, Keyboard, Modal, Platform, Pressable, StyleSheet, Text, TextInput, useWindowDimensions, View } from "react-native";
import { useSafeAreaInsets } from "react-native-safe-area-context";
import { Check, ChevronDown, Search, X } from "lucide-react-native";
import { FIELD, fieldStyles } from "@/components/ui";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

export type SelectOption = { value: string; label: string; subtitle?: string };

/** Lists longer than this get a search box in the sheet. */
const SEARCH_AFTER = 8;

/**
 * A drop-down select that looks exactly like TextField (label, 52dp shell,
 * error/hint) and opens a bottom sheet: drag handle, title, search for long
 * lists, 52dp rows with a check on the current value. Closes on pick,
 * backdrop, close button or the Android back button. Safe-area aware and
 * height-bounded so the list always scrolls (native and web).
 */
export function SelectField({
  label,
  value,
  options,
  onChange,
  error,
  hint,
  placeholder,
  disabled,
  loading,
  title,
  sheetHeader,
}: {
  label: string;
  value?: string;
  options: SelectOption[];
  onChange: (value: string) => void;
  error?: string;
  hint?: string;
  placeholder?: string;
  disabled?: boolean;
  loading?: boolean;
  /** Sheet title (defaults to the label). */
  title?: string;
  /** Extra content at the top of the sheet, e.g. filter chips. */
  sheetHeader?: React.ReactNode;
}) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  const current = options.find((o) => o.value === value);
  const shown = current?.label ?? placeholder ?? t("chooseOption");
  return (
    <View style={fieldStyles.field}>
      <Text style={fieldStyles.label}>{label}</Text>
      <Pressable
        accessibilityRole="combobox"
        accessibilityLabel={`${label}: ${current?.label ?? t("notChosen")}`}
        accessibilityHint={hint}
        accessibilityState={{ expanded: open, disabled: !!disabled, busy: !!loading }}
        disabled={disabled || loading}
        onPress={() => setOpen(true)}
        style={({ pressed }) => [
          fieldStyles.control,
          pressed && fieldStyles.controlPressed,
          error ? fieldStyles.controlError : null,
          disabled ? fieldStyles.controlDisabled : null,
        ]}
      >
        <Text style={[fieldStyles.value, !current && fieldStyles.placeholder, disabled && fieldStyles.disabledText]}>{shown}</Text>
        {loading ? <ActivityIndicator size="small" color={colors.blue600} /> : <ChevronDown size={20} color={disabled ? colors.neutral400 : colors.neutral600} />}
      </Pressable>
      {error ? (
        <Text accessibilityRole="alert" accessibilityLiveRegion="polite" style={fieldStyles.error}>{error}</Text>
      ) : hint ? (
        <Text style={fieldStyles.hint}>{hint}</Text>
      ) : null}
      <OptionSheet
        visible={open}
        title={title ?? label}
        options={options}
        value={value}
        header={sheetHeader}
        onClose={() => setOpen(false)}
        onPick={(v) => {
          setOpen(false);
          onChange(v);
        }}
      />
    </View>
  );
}

/** The bottom sheet on its own (reused by pickers that own their trigger). */
export function OptionSheet({
  visible,
  title,
  options,
  value,
  onPick,
  onClose,
  header,
}: {
  visible: boolean;
  title: string;
  options: SelectOption[];
  value?: string;
  onPick: (value: string) => void;
  onClose: () => void;
  header?: React.ReactNode;
}) {
  const { t } = useTranslation();
  const insets = useSafeAreaInsets();
  const { height } = useWindowDimensions();
  // Keep the sheet (and its search box) above the software keyboard on native.
  const [keyboard, setKeyboard] = useState(0);
  useEffect(() => {
    if (Platform.OS === "web") return;
    const show = Keyboard.addListener(Platform.OS === "ios" ? "keyboardWillShow" : "keyboardDidShow", (e) => setKeyboard(e.endCoordinates.height));
    const hide = Keyboard.addListener(Platform.OS === "ios" ? "keyboardWillHide" : "keyboardDidHide", () => setKeyboard(0));
    return () => {
      show.remove();
      hide.remove();
    };
  }, []);
  const [q, setQ] = useState("");
  const searchable = options.length > SEARCH_AFTER;
  const rows = useMemo(() => {
    const needle = q.trim().toLowerCase();
    return needle ? options.filter((o) => `${o.label} ${o.subtitle ?? ""}`.toLowerCase().includes(needle)) : options;
  }, [options, q]);
  const close = () => {
    setQ("");
    onClose();
  };
  return (
    <Modal visible={visible} transparent animationType="slide" statusBarTranslucent onRequestClose={close}>
      <View style={s.root}>
        <Pressable style={s.backdrop} onPress={close} accessibilityRole="button" accessibilityLabel={t("mdClose")} />
        {/* Explicit max height: the list flexes inside it and always scrolls. */}
        <View style={[s.sheet, { maxHeight: Math.round((height - keyboard) * 0.85), paddingBottom: keyboard ? space.x3 : Math.max(insets.bottom, space.x4), marginBottom: Platform.OS === "ios" ? keyboard : 0 }]} accessibilityViewIsModal>
          <View style={s.handle} accessibilityElementsHidden importantForAccessibility="no-hide-descendants" />
          <View style={s.header}>
            <Text accessibilityRole="header" style={s.title}>{title}</Text>
            <Pressable accessibilityRole="button" accessibilityLabel={t("mdClose")} onPress={close} hitSlop={8} style={s.closeBtn}>
              <X size={20} color={colors.neutral600} />
            </Pressable>
          </View>
          {header}
          {searchable ? (
            <View style={s.search}>
              <Search size={18} color={colors.neutral600} />
              <TextInput
                value={q}
                onChangeText={setQ}
                placeholder={t("mdSearch")}
                placeholderTextColor={colors.neutral500}
                style={s.searchInput}
                autoCorrect={false}
                accessibilityLabel={t("mdSearch")}
              />
            </View>
          ) : null}
          <FlatList
            style={s.list}
            data={rows}
            keyExtractor={(o) => o.value}
            keyboardShouldPersistTaps="handled"
            initialNumToRender={20}
            ListEmptyComponent={<Text style={s.empty}>{t("mdNoMatch")}</Text>}
            renderItem={({ item }) => {
              const on = item.value === value;
              return (
                <Pressable
                  accessibilityRole="radio"
                  accessibilityLabel={item.subtitle ? `${item.label}. ${item.subtitle}` : item.label}
                  accessibilityState={{ selected: on, checked: on }}
                  onPress={() => {
                    setQ("");
                    onPick(item.value);
                  }}
                  style={({ pressed }) => [s.option, on && s.optionOn, pressed && s.optionPressed]}
                >
                  <View style={s.flex}>
                    <Text style={[s.optionText, on && s.optionTextOn]}>{item.label}</Text>
                    {item.subtitle ? <Text style={s.optionSub}>{item.subtitle}</Text> : null}
                  </View>
                  {on ? <Check size={20} color={colors.blue600} /> : null}
                </Pressable>
              );
            }}
          />
        </View>
      </View>
    </Modal>
  );
}

const s = StyleSheet.create({
  root: { flex: 1, justifyContent: "flex-end" },
  backdrop: { ...StyleSheet.absoluteFillObject, backgroundColor: "rgba(11,31,78,0.45)" },
  sheet: {
    width: "100%",
    maxWidth: 720,
    alignSelf: "center",
    backgroundColor: colors.white,
    borderTopLeftRadius: radius.sheet,
    borderTopRightRadius: radius.sheet,
    paddingHorizontal: space.x4,
    paddingTop: space.x2,
    gap: space.x3,
  },
  handle: { alignSelf: "center", width: 40, height: 4, borderRadius: 2, backgroundColor: colors.neutral300 },
  header: { flexDirection: "row", alignItems: "center", gap: space.x2, minHeight: 44 },
  title: { ...type.cardTitle, color: colors.navy950, flex: 1 },
  closeBtn: { width: 44, height: 44, alignItems: "center", justifyContent: "center", marginRight: -space.x2 },
  search: {
    flexDirection: "row",
    alignItems: "center",
    gap: space.x2,
    minHeight: 48,
    borderWidth: 1,
    borderColor: colors.neutral300,
    borderRadius: FIELD.radius,
    paddingHorizontal: space.x3,
    backgroundColor: colors.neutral50,
  },
  searchInput: { ...type.body, flex: 1, color: colors.navy950, paddingVertical: 0, minHeight: 46 },
  list: { flexGrow: 0, flexShrink: 1 },
  flex: { flex: 1 },
  option: {
    minHeight: 52,
    flexDirection: "row",
    alignItems: "center",
    gap: space.x3,
    paddingHorizontal: space.x3,
    paddingVertical: space.x2,
    borderRadius: FIELD.radius,
  },
  optionOn: { backgroundColor: colors.blue50 },
  optionPressed: { backgroundColor: colors.neutral100 },
  optionText: { ...type.body, color: colors.navy950 },
  optionTextOn: { fontFamily: "Inter_600SemiBold", color: colors.blue700 },
  optionSub: { ...type.meta, color: colors.neutral600 },
  empty: { ...type.meta, color: colors.neutral600, paddingVertical: space.x4, textAlign: "center" },
});
