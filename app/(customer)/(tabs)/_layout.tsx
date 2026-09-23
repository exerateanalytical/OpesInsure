import React from "react";
import { Tabs } from "expo-router";
import {
  CircleUserRound,
  FileText,
  House,
  Scale,
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
      }}
    >
      <Tabs.Screen
        name="index"
        options={{ title: t("home"), tabBarIcon: icon(House) }}
      />
      <Tabs.Screen
        name="compare"
        options={{ title: t("compare"), tabBarIcon: icon(Scale) }}
      />
      <Tabs.Screen
        name="policies"
        options={{ title: t("policies"), tabBarIcon: icon(FileText) }}
      />
      <Tabs.Screen
        name="claims"
        options={{ title: t("claims"), tabBarIcon: icon(ShieldAlert) }}
      />
      <Tabs.Screen
        name="account"
        options={{ title: t("account"), tabBarIcon: icon(CircleUserRound) }}
      />
    </Tabs>
  );
}
