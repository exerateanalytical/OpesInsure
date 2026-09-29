import React from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { ChevronRight, LucideIcon } from "lucide-react-native";
import { colors, radius, space, tileIcon, tileIconSize, type } from "@/theme/tokens";
import { ripple } from "@/components/ui";
import { AgentIconBadge } from "@/components/agent/primitives";
import { usePartnerLook } from "@/hooks/usePartnerLook";
import { agentColors as ac, agentType as aT } from "@/theme/agent";
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
  // Partner portals: pronounced icon tile + agent type scale.
  const partner = !!usePartnerLook();
  return (
    <Pressable accessibilityRole="button" accessibilityLabel={[title, subtitle, status].filter(Boolean).join(", ")} onPress={onPress} android_ripple={ripple()} style={({ pressed }) => [s.row, partner && s.partnerRow, pressed && s.pressed]}>
      {partner ? (
        <AgentIconBadge icon={Icon} />
      ) : (
        <View style={s.icon}>
          <Icon size={tileIconSize(42)} color={colors.navy800} strokeWidth={tileIcon.stroke} />
        </View>
      )}
      <View style={s.copy}>
        <Text style={[s.title, partner && s.partnerTitle]}>{title}</Text>
        {subtitle ? <Text style={[s.sub, partner && s.partnerSub]}>{subtitle}</Text> : null}
        {status ? (
          <Text style={[s.status, partner && s.partnerStatus]}>{status.replaceAll("_", " ")}</Text>
        ) : null}
      </View>
      <View accessibilityElementsHidden importantForAccessibility="no-hide-descendants"><ChevronRight size={20} color={colors.neutral400} /></View>
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
  pressed: { opacity: 0.85 },
  partnerRow: { minHeight: 68, borderBottomColor: ac.border },
  partnerTitle: { ...aT.cardTitle, color: ac.heading },
  partnerSub: { ...aT.secondary, color: ac.secondary },
  partnerStatus: { ...aT.caption, color: ac.actionBlue },
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
    minHeight: 44,
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
