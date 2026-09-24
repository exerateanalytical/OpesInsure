import React from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { authColors, authRadius, authSpace, authType } from "@/theme/tokens";

export type ChannelOption<T extends string> = { key: T; label: string };

/** Segmented choice for where a verification code is delivered. */
export function ChannelPicker<T extends string>({
  label,
  options,
  value,
  onChange,
}: {
  label: string;
  options: ChannelOption<T>[];
  value: T;
  onChange: (value: T) => void;
}) {
  return (
    <View style={styles.wrap}>
      <Text style={styles.caption}>{label}</Text>
      <View accessibilityRole="radiogroup" style={styles.row}>
        {options.map((o) => {
          const selected = o.key === value;
          return (
            <Pressable
              key={o.key}
              accessibilityRole="radio"
              accessibilityState={{ selected }}
              onPress={() => onChange(o.key)}
              style={[styles.option, selected && styles.selected]}
            >
              <Text style={[styles.text, selected && styles.selectedText]}>{o.label}</Text>
            </Pressable>
          );
        })}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { gap: authSpace[1] },
  caption: { ...authType.label, fontSize: 13, color: authColors.slate500 },
  row: { flexDirection: "row", gap: authSpace[2] },
  option: {
    flex: 1,
    minHeight: 44,
    borderRadius: authRadius.lg,
    borderWidth: 1.5,
    borderColor: authColors.ice200,
    alignItems: "center",
    justifyContent: "center",
  },
  selected: { borderColor: authColors.blue500, backgroundColor: authColors.ice50 },
  text: { ...authType.label, color: authColors.textSecondary },
  selectedText: { color: authColors.blue500 },
});
