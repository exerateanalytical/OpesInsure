import React from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { LucideIcon } from "lucide-react-native";
import { authColors, authIcon, authRadius, authSpace, authType } from "@/theme/tokens";

export type AccountType = { key: string; label: string; icon: LucideIcon };

export function AccountTypeSelector({
  options,
  value,
  onChange,
}: {
  options: AccountType[];
  value: string;
  onChange: (key: string) => void;
}) {
  return (
    <View style={styles.row} accessibilityRole="tablist">
      {options.map((option) => {
        const selected = option.key === value;
        const Icon = option.icon;
        return (
          <Pressable
            key={option.key}
            accessibilityRole="tab"
            accessibilityState={{ selected }}
            accessibilityLabel={option.label}
            onPress={() => onChange(option.key)}
            style={[styles.pill, selected && styles.pillSelected]}
          >
            <Icon
              size={authIcon.normal}
              strokeWidth={authIcon.strokeWidth}
              color={selected ? authColors.white : authColors.navy800}
            />
            <Text style={[styles.label, selected && styles.labelSelected]}>{option.label}</Text>
          </Pressable>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: "row",
    backgroundColor: authColors.ice50,
    borderRadius: authRadius.pill,
    padding: 4,
    gap: 4,
  },
  pill: {
    flex: 1,
    alignItems: "center",
    justifyContent: "center",
    gap: 4,
    paddingVertical: authSpace[2],
    borderRadius: authRadius.pill,
  },
  pillSelected: { backgroundColor: authColors.blue500 },
  label: { ...authType.label, fontSize: 12, color: authColors.navy800 },
  labelSelected: { color: authColors.white },
});
