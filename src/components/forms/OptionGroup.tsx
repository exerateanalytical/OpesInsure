import React from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { FIELD, fieldStyles } from "@/components/ui";
import { colors, space, type } from "@/theme/tokens";
import type { SelectOption } from "@/components/forms/SelectField";

/**
 * Short closed lists (2–4 options): every choice visible as a 52dp radio
 * row, so there is nothing to open. Longer lists use SelectField.
 */
export function OptionGroup({ label, value, options, onChange, error, hint }: { label: string; value?: string; options: SelectOption[]; onChange: (v: string) => void; error?: string; hint?: string }) {
  return (
    <View style={fieldStyles.field}>
      <Text style={fieldStyles.label}>{label}</Text>
      <View accessibilityRole="radiogroup" accessibilityLabel={label} style={s.group}>
        {options.map((o) => {
          const on = o.value === value;
          return (
            <Pressable
              key={o.value}
              accessibilityRole="radio"
              accessibilityLabel={`${label}: ${o.label}`}
              accessibilityState={{ selected: on, checked: on }}
              onPress={() => onChange(o.value)}
              style={({ pressed }) => [s.row, on && s.rowOn, error && !on ? s.rowError : null, pressed && s.pressed]}
            >
              <View style={[s.dot, on && s.dotOn]}>{on ? <View style={s.dotInner} /> : null}</View>
              <View style={s.flex}>
                <Text style={[s.text, on && s.textOn]}>{o.label}</Text>
                {o.subtitle ? <Text style={fieldStyles.hint}>{o.subtitle}</Text> : null}
              </View>
            </Pressable>
          );
        })}
      </View>
      {error ? (
        <Text accessibilityRole="alert" accessibilityLiveRegion="polite" style={fieldStyles.error}>{error}</Text>
      ) : hint ? (
        <Text style={fieldStyles.hint}>{hint}</Text>
      ) : null}
    </View>
  );
}

const s = StyleSheet.create({
  group: { gap: space.x2 },
  flex: { flex: 1 },
  row: {
    minHeight: FIELD.height,
    flexDirection: "row",
    alignItems: "center",
    gap: space.x3,
    paddingHorizontal: FIELD.padX,
    paddingVertical: space.x2,
    borderWidth: 1,
    borderColor: colors.neutral300,
    borderRadius: FIELD.radius,
    backgroundColor: colors.white,
  },
  rowOn: { borderColor: colors.blue600, backgroundColor: colors.blue50 },
  rowError: { borderColor: colors.danger },
  pressed: { opacity: 0.85 },
  dot: { width: 20, height: 20, borderRadius: 10, borderWidth: 2, borderColor: colors.neutral400, alignItems: "center", justifyContent: "center" },
  dotOn: { borderColor: colors.blue600 },
  dotInner: { width: 10, height: 10, borderRadius: 5, backgroundColor: colors.blue600 },
  text: { ...type.body, color: colors.navy950 },
  textOn: { fontFamily: "Inter_600SemiBold" },
});
