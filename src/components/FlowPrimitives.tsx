import React from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { ChevronRight, LucideIcon } from "lucide-react-native";
import { colors, radius, space, type } from "@/theme/tokens";
export function FlowRow({
  title,
  subtitle,
  status,
  icon: Icon,
  onPress,
}: {
  title: string;
  subtitle?: string;
  status?: string;
  icon: LucideIcon;
  onPress?: () => void;
}) {
  return (
    <Pressable accessibilityRole="button" accessibilityLabel={[title, subtitle, status].filter(Boolean).join(", ")} onPress={onPress} style={s.row}>
      <View style={s.icon}>
        <Icon size={21} color={colors.blue600} />
      </View>
      <View style={s.copy}>
        <Text style={s.title}>{title}</Text>
        {subtitle ? <Text style={s.sub}>{subtitle}</Text> : null}
        {status ? (
          <Text style={s.status}>{status.replaceAll("_", " ")}</Text>
        ) : null}
      </View>
      <ChevronRight accessibilityElementsHidden importantForAccessibility="no-hide-descendants" size={20} color={colors.neutral400} />
    </Pressable>
  );
}
export function Step({
  label,
  complete,
}: {
  label: string;
  complete: boolean;
}) {
  return (
    <View style={s.step}>
      <View style={[s.dot, complete && s.done]} />
      <Text style={[s.sub, complete && s.stepDone]}>{label}</Text>
    </View>
  );
}
const s = StyleSheet.create({
  row: {
    minHeight: 78,
    flexDirection: "row",
    alignItems: "center",
    gap: space.x3,
    borderBottomWidth: 1,
    borderBottomColor: colors.neutral100,
  },
  icon: {
    width: 42,
    height: 42,
    borderRadius: radius.control,
    backgroundColor: colors.blue50,
    alignItems: "center",
    justifyContent: "center",
  },
  copy: { flex: 1, gap: 3 },
  title: { ...type.label, color: colors.navy950 },
  sub: { ...type.meta, color: colors.neutral600 },
  status: {
    ...type.caption,
    color: colors.blue700,
    textTransform: "capitalize",
  },
  step: {
    flexDirection: "row",
    alignItems: "center",
    gap: space.x3,
    minHeight: 42,
  },
  dot: {
    width: 12,
    height: 12,
    borderRadius: 6,
    backgroundColor: colors.neutral300,
  },
  done: { backgroundColor: colors.successText },
  stepDone: { color: colors.navy950 },
});
