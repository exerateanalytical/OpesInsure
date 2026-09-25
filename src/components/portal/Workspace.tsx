import React from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { Check, LucideIcon } from "lucide-react-native";
import { Card } from "@/components/ui";
import { useColumns } from "@/components/responsive";
import { colors, radius, space, type } from "@/theme/tokens";
import { translateNow } from "@/i18n";

export type WorkspaceItem = {
  label: string;
  subtitle: string;
  icon: LucideIcon;
  href: string;
};

/** The partner dashboard grid: every menu entry that is not a tab. */
export function WorkspaceMenu({ items }: { items: WorkspaceItem[] }) {
  const grid = useColumns();
  return (
    <View style={grid.row}>
      {items.map((it) => (
        <Pressable
          key={it.href}
          accessibilityRole="button"
          accessibilityLabel={`${it.label}, ${it.subtitle}`}
          onPress={() => router.push(it.href as never)}
          style={({ pressed }) => [grid.item, pressed && s.pressed]}
        >
          <Card style={s.tile}>
            <View style={s.icon}>
              <it.icon size={22} color={colors.blue600} />
            </View>
            <Text style={s.label}>{it.label}</Text>
            <Text style={s.sub}>{it.subtitle}</Text>
          </Card>
        </Pressable>
      ))}
    </View>
  );
}

/** Single-select chip row (status filters, decision choice). */
export function ChoiceChips<T extends string>({
  options,
  value,
  onChange,
  label,
}: {
  options: { value: T; label: string }[];
  value: T | null;
  onChange: (v: T) => void;
  label: string;
}) {
  return (
    <View accessibilityRole="radiogroup" accessibilityLabel={label} style={s.chips}>
      {options.map((o) => {
        const on = o.value === value;
        return (
          <Pressable
            key={o.value}
            accessibilityRole="radio"
            accessibilityState={{ selected: on }}
            accessibilityLabel={o.label}
            onPress={() => onChange(o.value)}
            style={[s.chip, on && s.chipOn]}
          >
            <Text style={[s.chipText, on && s.chipTextOn]}>{o.label}</Text>
          </Pressable>
        );
      })}
    </View>
  );
}

/** Explicit consent capture: an unchecked box the agent must tick. */
export function ConsentCheckbox({
  checked,
  onChange,
  label,
}: {
  checked: boolean;
  onChange: (next: boolean) => void;
  label: string;
}) {
  return (
    <Pressable
      accessibilityRole="checkbox"
      accessibilityState={{ checked }}
      accessibilityLabel={label}
      onPress={() => onChange(!checked)}
      style={s.consent}
    >
      <View style={[s.box, checked && s.boxOn]}>
        {checked ? <Check size={16} color={colors.white} /> : null}
      </View>
      <Text style={s.consentText}>{label}</Text>
    </Pressable>
  );
}

/** Inline message after an action (success or failure). */
export function Notice({ text, tone }: { text: string | null; tone: "ok" | "error" }) {
  if (!text) return null;
  return (
    <Text
      accessibilityRole="alert"
      accessibilityLiveRegion="polite"
      style={[s.notice, tone === "error" ? s.noticeError : s.noticeOk]}
    >
      {text}
    </Text>
  );
}

export function errorMessage(e: unknown, fallback?: string) {
  return e instanceof Error && e.message ? e.message : (fallback ?? translateNow("errGeneric"));
}

const s = StyleSheet.create({
  pressed: { opacity: 0.82 },
  tile: { minHeight: 112, gap: space.x1 },
  icon: {
    width: 40,
    height: 40,
    borderRadius: 20,
    alignItems: "center",
    justifyContent: "center",
  },
  label: { ...type.label, color: colors.navy950 },
  sub: { ...type.meta, color: colors.neutral600 },
  chips: { flexDirection: "row", flexWrap: "wrap", gap: space.x2 },
  chip: {
    minHeight: 40,
    paddingHorizontal: space.x3,
    borderRadius: radius.control,
    borderWidth: 1,
    borderColor: colors.neutral300,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.white,
  },
  chipOn: { backgroundColor: colors.blue600, borderColor: colors.blue600 },
  chipText: { ...type.label, color: colors.navy950 },
  chipTextOn: { color: colors.white },
  consent: { flexDirection: "row", alignItems: "flex-start", gap: space.x3, minHeight: 48 },
  box: {
    width: 24,
    height: 24,
    marginTop: 2,
    borderRadius: 6,
    borderWidth: 2,
    borderColor: colors.neutral400,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.white,
  },
  boxOn: { backgroundColor: colors.blue600, borderColor: colors.blue600 },
  consentText: { ...type.body, color: colors.navy950, flex: 1 },
  notice: { ...type.meta, paddingVertical: space.x1 },
  noticeError: { color: colors.dangerText },
  noticeOk: { color: colors.successText },
});
