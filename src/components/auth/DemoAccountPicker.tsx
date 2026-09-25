import React, { useState } from "react";
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from "react-native";
import {
  Building2,
  Check,
  ChevronDown,
  ChevronUp,
  FlaskConical,
  Handshake,
  LucideIcon,
  UserRound,
  UsersRound,
} from "lucide-react-native";
import { authColors, authRadius, authSpace, authType, colors } from "@/theme/tokens";
import { demoOptionLabel, type DemoAccountLike } from "@/lib/demoLogin";
import { useTranslation } from "@/i18n";

/** Role icon per demo account (server role_code); customer by default. */
const roleIcon = (role?: string): LucideIcon => {
  const r = (role ?? "").toUpperCase();
  if (r.includes("CARRIER") || r.includes("INSURER")) return Building2;
  if (r.includes("BROKER")) return Handshake;
  if (r.includes("AGENT")) return UserRound;
  return UsersRound;
};

/**
 * Demo sign-in as a dropdown select: one field ("Choose a demo account")
 * that expands an option list right underneath it, inside the sign-in card.
 * Picking an option signs in straight away. No Modal, so it behaves the
 * same on Android, iOS and the browser preview.
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
  const [open, setOpen] = useState(false);
  const busy = accounts.find((a) => a.phone_e164 === busyPhone);
  const Chevron = open ? ChevronUp : ChevronDown;
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
        onPress={() => setOpen((v) => !v)}
        style={({ pressed }) => [styles.select, open && styles.selectOpen, pressed && styles.pressed, disabled && styles.disabled]}
      >
        <Text style={[styles.selectText, !busy && styles.placeholder]} numberOfLines={1}>
          {busy ? `${t("signingIn")} ${demoOptionLabel(busy)}` : t("demoChoose")}
        </Text>
        {busy ? <ActivityIndicator color={authColors.blue500} /> : <Chevron size={20} color={authColors.slate500} />}
      </Pressable>
      {open ? (
        <View accessibilityRole="menu" style={styles.menu}>
          {accounts.map((item, index) => {
            const Icon = roleIcon(item.role_code);
            const selected = busyPhone === item.phone_e164;
            return (
              <Pressable
                key={item.phone_e164}
                accessibilityRole="menuitem"
                accessibilityLabel={demoOptionLabel(item)}
                accessibilityState={{ selected }}
                disabled={disabled}
                onPress={() => {
                  setOpen(false);
                  onPick(item);
                }}
                style={({ pressed }) => [styles.option, index > 0 && styles.optionBorder, pressed && styles.optionPressed]}
              >
                <View style={styles.optionIcon}>
                  <Icon size={26} color={authColors.navy800} strokeWidth={1.8} />
                </View>
                <View style={styles.flex}>
                  <Text style={styles.optionLabel}>{item.label}</Text>
                  <Text style={styles.optionMeta} numberOfLines={1}>
                    {item.full_name ? `${item.full_name} · ` : ""}
                    {item.phone_e164}
                  </Text>
                </View>
                {selected ? <Check size={18} color={colors.success} /> : null}
              </Pressable>
            );
          })}
        </View>
      ) : (
        <Text style={styles.hint}>{t("demoPickHint")}</Text>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: {
    gap: authSpace[1],
    borderRadius: authRadius.lg,
    borderWidth: 1,
    borderColor: colors.gold100,
    backgroundColor: colors.gold50,
    padding: authSpace[3],
  },
  titleRow: { flexDirection: "row", alignItems: "center", gap: authSpace[1], marginBottom: 2 },
  title: { ...authType.label, color: authColors.navy950 },
  select: {
    minHeight: 52,
    flexDirection: "row",
    alignItems: "center",
    gap: authSpace[2],
    borderWidth: 1.5,
    borderColor: authColors.ice200,
    borderRadius: authRadius.lg,
    paddingHorizontal: authSpace[3],
    backgroundColor: authColors.white,
  },
  selectOpen: { borderColor: authColors.blue500 },
  selectText: { ...authType.body, color: authColors.navy950, flex: 1 },
  placeholder: { color: authColors.slate500 },
  hint: { ...authType.label, fontSize: 12, fontFamily: "Inter_400Regular", color: authColors.textSecondary },
  pressed: { opacity: 0.85 },
  disabled: { opacity: 0.55 },
  menu: {
    borderWidth: 1.5,
    borderColor: authColors.blue500,
    borderRadius: authRadius.lg,
    backgroundColor: authColors.white,
    overflow: "hidden",
  },
  option: { minHeight: 56, flexDirection: "row", alignItems: "center", gap: authSpace[2], paddingHorizontal: authSpace[3], paddingVertical: authSpace[2] },
  optionBorder: { borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: authColors.ice200 },
  optionPressed: { backgroundColor: authColors.ice50 },
  optionIcon: {
    width: 36,
    height: 36,
    borderRadius: 18,
    alignItems: "center",
    justifyContent: "center",
  },
  flex: { flex: 1 },
  optionLabel: { ...authType.label, color: authColors.navy950 },
  optionMeta: { ...authType.label, fontSize: 12, fontFamily: "Inter_400Regular", color: authColors.textSecondary },
});
