import React from "react";
import { Tabs } from "expo-router";
import { View } from "react-native";
import {
  UserRound,
  Compass,
  FileText,
  House,
  ShieldCheck,
} from "lucide-react-native";
import { colors } from "@/theme/tokens";
import { useTranslation } from "@/i18n";
import { useSafeAreaInsets } from "react-native-safe-area-context";

const icon = (Icon: any) => {
  function TabIcon({ color, size, focused }: { color: string; size: number; focused: boolean }) {
    return (
      <View style={{ alignItems: "center" }}>
        <View style={{ position: "absolute", top: -9, width: 36, height: 3, borderRadius: 2, backgroundColor: focused ? colors.gold500 : "transparent" }} />
        <Icon color={color} size={size + 2} strokeWidth={focused ? 2.2 : 1.9} />
      </View>
    );
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
        tabBarActiveTintColor: colors.gold600,
        tabBarInactiveTintColor: colors.navy800,
        tabBarStyle: {
          height: 72 + insets.bottom,
          paddingTop: 9,
          paddingBottom: 8 + insets.bottom,
          borderTopColor: colors.neutral200,
          borderTopWidth: 1,
          backgroundColor: colors.white,
        },
        tabBarLabelStyle: { fontFamily: "Inter_500Medium", fontSize: 12, lineHeight: 16, marginTop: 2 },
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
        options={{ title: t("claims"), tabBarAccessibilityLabel: t("claims"), tabBarIcon: icon(ShieldCheck) }}
      />
      <Tabs.Screen
        name="profile"
        options={{ title: t("profile"), tabBarAccessibilityLabel: t("profile"), tabBarIcon: icon(UserRound) }}
      />
      {/* The comparison screen (purchase flow) stays routable, reached from
          Home / Explore "Compare Insurance", but is no longer a tab. */}
      <Tabs.Screen name="compare" options={{ href: null }} />
    </Tabs>
  );
}
