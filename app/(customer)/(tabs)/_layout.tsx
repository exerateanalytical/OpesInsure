import React from "react";
import { Tabs } from "expo-router";
import { useTranslation } from "@/i18n";
import { useSafeAreaInsets } from "react-native-safe-area-context";
import {
  customerTabBarIcon,
  customerTabBarStyle,
  customerTabColors,
  customerTabLabelStyle,
} from "@/components/customer/CustomerTabBar";

/**
 * Customer bottom navigation: Home | Explore | Policies | Claims | Profile.
 * Stack screens outside this navigator (quote, checkout, policy, claim...)
 * show the same bar through CustomerScreenFrame (app/_layout.tsx); both use
 * the shared design in src/components/customer/CustomerTabBar.tsx.
 */
export default function CustomerTabs() {
  const { t } = useTranslation();
  // SDK 54 is edge-to-edge on Android: without the bottom inset the tab bar
  // sits under the system navigation bar.
  const insets = useSafeAreaInsets();
  return (
    <Tabs
      screenOptions={{
        headerShown: false,
        tabBarActiveTintColor: customerTabColors.active,
        tabBarInactiveTintColor: customerTabColors.inactive,
        tabBarStyle: customerTabBarStyle(insets.bottom),
        tabBarLabelStyle: customerTabLabelStyle,
        tabBarAllowFontScaling: false,
      }}
    >
      <Tabs.Screen
        name="index"
        options={{ title: t("home"), tabBarAccessibilityLabel: t("home"), tabBarIcon: customerTabBarIcon("home") }}
      />
      <Tabs.Screen
        name="explore"
        options={{ title: t("explore"), tabBarAccessibilityLabel: t("explore"), tabBarIcon: customerTabBarIcon("explore") }}
      />
      <Tabs.Screen
        name="policies"
        options={{ title: t("policies"), tabBarAccessibilityLabel: t("policies"), tabBarIcon: customerTabBarIcon("policies") }}
      />
      <Tabs.Screen
        name="claims"
        options={{ title: t("claims"), tabBarAccessibilityLabel: t("claims"), tabBarIcon: customerTabBarIcon("claims") }}
      />
      <Tabs.Screen
        name="profile"
        options={{ title: t("profile"), tabBarAccessibilityLabel: t("profile"), tabBarIcon: customerTabBarIcon("profile") }}
      />
      {/* The comparison screen (purchase flow) stays routable, reached from
          Home / Explore "Compare Insurance", but is no longer a tab. */}
      <Tabs.Screen name="compare" options={{ href: null }} />
    </Tabs>
  );
}
