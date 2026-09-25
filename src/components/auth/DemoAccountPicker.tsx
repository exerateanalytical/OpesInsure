import React, { useState } from "react";
import { ActivityIndicator, FlatList, Modal, Pressable, StyleSheet, Text, View } from "react-native";
import { useSafeAreaInsets } from "react-native-safe-area-context";
import { Check, ChevronDown, FlaskConical, X } from "lucide-react-native";
import { authColors, authSpace, colors, radius, type } from "@/theme/tokens";
import { demoOptionLabel, type DemoAccountLike } from "@/lib/demoLogin";
import { useTranslation } from "@/i18n";

/**
 * One select field ("Choose a demo account") instead of a list of buttons.
 * Opens a bottom sheet (core RN Modal: no native dependency) listing the
 * server's demo accounts; picking one signs in straight away.
 */
export function DemoAccountPicker({
  accounts,
  busyPhone,
  disabled,
  onPick,
}: {
  accounts: DemoAccountLike[];
  busyPhone: string | null;
  disabled?: boolean;
  onPick: (account: DemoAccountLike) => void;
}) {
  const { t } = useTranslation();
  const insets = useSafeAreaInsets();
  const [open, setOpen] = useState(false);
  const busy = accounts.find((a) => a.phone_e164 === busyPhone);
  return (
    <View style={styles.wrap}>
      <View style={styles.titleRow}>
        <FlaskConical size={16} color={colors.gold600} />
        <Text style={styles.title}>{t("demoAccounts")}</Text>
      </View>
      <Pressable
        accessibilityRole="combobox"
        accessibilityLabel={t("demoChoose")}
        accessibilityState={{ expanded: open, disabled: !!disabled, busy: !!busy }}
        disabled={disabled}
        onPress={() => setOpen(true)}
        style={({ pressed }) => [styles.select, pressed && styles.pressed, disabled && styles.disabled]}
      >
        <Text style={[styles.selectText, !busy && styles.placeholder]} numberOfLines={1}>
          {busy ? `${t("signingIn")} ${demoOptionLabel(busy)}` : t("demoChoose")}
        </Text>
        {busy ? <ActivityIndicator color={colors.blue600} /> : <ChevronDown size={20} color={colors.neutral600} />}
      </Pressable>
      <Text style={styles.hint}>{t("demoPickHint")}</Text>
      <Modal visible={open} transparent animationType="slide" onRequestClose={() => setOpen(false)} statusBarTranslucent>
        <Pressable style={styles.backdrop} accessibilityRole="button" accessibilityLabel={t("close")} onPress={() => setOpen(false)} />
        <View style={[styles.sheet, { paddingBottom: authSpace[4] + insets.bottom }]} accessibilityViewIsModal>
          <View style={styles.sheetHead}>
            <Text accessibilityRole="header" style={styles.sheetTitle}>{t("demoChoose")}</Text>
            <Pressable accessibilityRole="button" accessibilityLabel={t("close")} hitSlop={12} onPress={() => setOpen(false)}>
              <X size={22} color={colors.navy950} />
            </Pressable>
          </View>
          <FlatList
            data={accounts}
            keyExtractor={(a) => a.phone_e164}
            ItemSeparatorComponent={() => <View style={styles.sep} />}
            renderItem={({ item }) => (
              <Pressable
                accessibilityRole="menuitem"
                accessibilityLabel={demoOptionLabel(item)}
                onPress={() => {
                  setOpen(false);
                  onPick(item);
                }}
                style={({ pressed }) => [styles.option, pressed && styles.pressed]}
              >
                <View style={styles.flex}>
                  <Text style={styles.optionLabel}>{item.label}</Text>
                  <Text style={styles.optionMeta}>
                    {item.full_name ? `${item.full_name} · ` : ""}
                    {item.phone_e164}
                  </Text>
                </View>
                {busyPhone === item.phone_e164 ? <Check size={18} color={colors.success} /> : null}
              </Pressable>
            )}
          />
        </View>
      </Modal>
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: {
    marginHorizontal: authSpace[5],
    marginTop: authSpace[4],
    backgroundColor: authColors.white,
    borderRadius: radius.feature,
    borderWidth: 1,
    borderColor: colors.gold100,
    padding: authSpace[4],
    gap: authSpace[2],
  },
  titleRow: { flexDirection: "row", alignItems: "center", gap: authSpace[1] },
  title: { ...type.label, color: colors.navy950 },
  select: {
    minHeight: 52,
    flexDirection: "row",
    alignItems: "center",
    gap: authSpace[2],
    borderWidth: 1.5,
    borderColor: colors.neutral300,
    borderRadius: radius.control,
    paddingHorizontal: authSpace[3],
    backgroundColor: colors.neutral50,
  },
  selectText: { ...type.body, color: colors.navy950, flex: 1 },
  placeholder: { color: colors.neutral600 },
  hint: { ...type.meta, color: colors.neutral600 },
  pressed: { opacity: 0.75 },
  disabled: { opacity: 0.55 },
  backdrop: { flex: 1, backgroundColor: "rgba(15,21,53,0.55)" },
  sheet: {
    maxHeight: "75%",
    backgroundColor: colors.white,
    borderTopLeftRadius: radius.sheet,
    borderTopRightRadius: radius.sheet,
    paddingHorizontal: authSpace[4],
    paddingTop: authSpace[3],
  },
  sheetHead: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", paddingVertical: authSpace[2] },
  sheetTitle: { ...type.cardTitle, color: colors.navy950, flex: 1 },
  sep: { height: StyleSheet.hairlineWidth, backgroundColor: colors.neutral200 },
  option: { minHeight: 56, flexDirection: "row", alignItems: "center", paddingVertical: authSpace[2], gap: authSpace[2] },
  flex: { flex: 1 },
  optionLabel: { ...type.body, fontFamily: "Inter_600SemiBold", color: colors.navy950 },
  optionMeta: { ...type.meta, color: colors.neutral600 },
});
