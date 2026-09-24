import React from "react";
import { Tabs } from "expo-router";
import {
  CircleUserRound,
  Compass,
  FileText,
  House,
  ShieldAlert,
} from "lucide-react-native";
import { colors } from "@/theme/tokens";
import { useTranslation } from "@/i18n";
import { useSafeAreaInsets } from "react-native-safe-area-context";

const icon = (Icon: any) => {
  function TabIcon({ color, size }: { color: string; size: number }) {
    return <Icon color={color} size={size} strokeWidth={2} />;
  }
  return TabIcon;
};

/** Customer bottom navigation: Home | Explore | Policies | Claims | Profile. */
export default function CustomerTabs() {
  const { t } = useTranslation();
  // SDK 54 is edge-to-edge on Android: without the bottom inset the tab bar
  // sits under the system navigation bar.
  const insets = useSafeAreaInsets();
  return (
    <Tabs
      screenOptions={{
        headerShown: false,
        tabBarActiveTintColor: colors.blue600,
        tabBarInactiveTintColor: colors.neutral500,
        tabBarStyle: {
          height: 60 + insets.bottom,
          paddingTop: 7,
          paddingBottom: 8 + insets.bottom,
          borderTopColor: colors.neutral200,
          backgroundColor: colors.white,
        },
        tabBarLabelStyle: { fontFamily: "Inter_600SemiBold", fontSize: 11 },
        tabBarAllowFontScaling: false,
      }}
    >
      <Tabs.Screen
        name="index"
        options={{ title: t("home"), tabBarAccessibilityLabel: t("home"), tabBarIcon: icon(House) }}
      />
      <Tabs.Screen
        name="explore"
        options={{ title: t("explore"), tabBarAccessibilityLabel: t("explore"), tabBarIcon: icon(Compass) }}
      />
      <Tabs.Screen
        name="policies"
        options={{ title: t("policies"), tabBarAccessibilityLabel: t("policies"), tabBarIcon: icon(FileText) }}
      />
      <Tabs.Screen
        name="claims"
        options={{ title: t("claims"), tabBarAccessibilityLabel: t("claims"), tabBarIcon: icon(ShieldAlert) }}
      />
      <Tabs.Screen
        name="profile"
        options={{ title: t("profile"), tabBarAccessibilityLabel: t("profile"), tabBarIcon: icon(CircleUserRound) }}
      />
      {/* The comparison screen (purchase flow) stays routable, reached from
          Home / Explore "Compare Insurance", but is no longer a tab. */}
      <Tabs.Screen name="compare" options={{ href: null }} />
    </Tabs>
  );
}
