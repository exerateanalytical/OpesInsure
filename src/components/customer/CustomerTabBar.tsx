import React, { ReactElement, useEffect } from "react";
import { BackHandler, Platform, Pressable, StyleSheet, Text, View } from "react-native";
import { Href, router } from "expo-router";
import { SafeAreaInsetsContext, useSafeAreaInsets } from "react-native-safe-area-context";
import { Compass, FileText, House, LucideIcon, ShieldCheck, UserRound } from "lucide-react-native";
import { useTranslation } from "@/i18n";
import { useKeyboardVisible } from "@/hooks/useKeyboardVisible";
import { colors } from "@/theme/tokens";
import {
  CUSTOMER_TABS,
  customerBackAction,
  customerBarVisible,
  customerTabForRoute,
  type CustomerTab,
} from "@/lib/customerNav";

/**
 * The customer bottom navigation, one design for both places it appears:
 * the tab navigator (app/(customer)/(tabs)/_layout.tsx) and every customer
 * stack screen outside it (CustomerScreenFrame, wired in app/_layout.tsx).
 */

export const CUSTOMER_TAB_ICONS: Record<CustomerTab, LucideIcon> = {
  home: House,
  explore: Compass,
  policies: FileText,
  claims: ShieldCheck,
  profile: UserRound,
};

export const customerTabColors = { active: colors.gold600, inactive: colors.navy800 } as const;

/** 72dp bar (plus the Android nav bar / iOS home indicator) with a hairline top border. */
export const customerTabBarStyle = (bottomInset: number) => ({
  height: 72 + bottomInset,
  paddingTop: 9,
  paddingBottom: 8 + bottomInset,
  borderTopColor: colors.neutral200,
  borderTopWidth: 1,
  backgroundColor: colors.white,
});

export const customerTabLabelStyle = { fontFamily: "Inter_500Medium", fontSize: 12, lineHeight: 16, marginTop: 2 } as const;

/** Tab icon with the gold indicator above the focused tab. */
export function CustomerTabIcon({ tab, color, size, focused }: { tab: CustomerTab; color: string; size: number; focused: boolean }) {
  const Icon = CUSTOMER_TAB_ICONS[tab];
  return (
    <View style={s.iconWrap}>
      <View style={[s.indicator, focused && s.indicatorOn]} />
      <Icon color={color} size={size + 2} strokeWidth={focused ? 2.2 : 1.9} />
    </View>
  );
}

/** tabBarIcon for <Tabs.Screen>. */
export const customerTabBarIcon = (tab: CustomerTab) => {
  function TabIcon({ color, size, focused }: { color: string; size: number; focused: boolean }) {
    return <CustomerTabIcon tab={tab} color={color} size={size} focused={focused} />;
  }
  return TabIcon;
};

/** Default bottom-tabs icon size (uikit, regular). */
const ICON_SIZE = 25;

/**
 * The same bar for stack screens. A tab press returns to the tab navigator
 * already in the stack (screens above it are dismissed) and opens that tab;
 * with no tab navigator underneath (cold start from a notification) it
 * replaces the current screen.
 */
export function CustomerBottomBar({ active }: { active: CustomerTab | null }) {
  const { t } = useTranslation();
  const insets = useSafeAreaInsets();
  return (
    <View accessibilityRole="tablist" style={[s.bar, customerTabBarStyle(insets.bottom)]}>
      {CUSTOMER_TABS.map((tab) => {
        const focused = tab.key === active;
        const color = focused ? customerTabColors.active : customerTabColors.inactive;
        return (
          <Pressable
            key={tab.key}
            accessibilityRole="tab"
            accessibilityLabel={t(tab.label)}
            accessibilityState={{ selected: focused }}
            onPress={() => router.dismissTo(tab.href as Href)}
            style={({ pressed }) => [s.tab, pressed && s.pressed]}
          >
            <CustomerTabIcon tab={tab.key} color={color} size={ICON_SIZE} focused={focused} />
            <Text allowFontScaling={false} numberOfLines={1} style={[customerTabLabelStyle, { color }]}>
              {t(tab.label)}
            </Text>
          </Pressable>
        );
      })}
    </View>
  );
}

type FrameProps = {
  route: { name: string; params?: object };
  navigation: { canGoBack(): boolean; isFocused(): boolean };
  children: ReactElement;
};

/**
 * Wraps every customer stack screen (Stack `screenLayout` in app/_layout.tsx):
 * - shows the customer bottom bar under the screen (under any pinned CTA
 *   footer) except where src/lib/customerNav.ts hides it, and while the
 *   keyboard is up; the screen then gets a zero bottom inset because the bar
 *   already clears the Android nav bar / home indicator;
 * - Android hardware back never closes the app or reopens a finished
 *   payment (customerBackAction).
 */
export function CustomerScreenFrame({ route, navigation, children }: FrameProps) {
  const name = route.name;
  const showBar = customerBarVisible(name);
  const keyboard = useKeyboardVisible();
  const insets = useSafeAreaInsets();
  const params = route.params as { proposalId?: unknown } | undefined;

  useEffect(() => {
    if (Platform.OS !== "android") return;
    const sub = BackHandler.addEventListener("hardwareBackPress", () => {
      if (!navigation.isFocused()) return false;
      const action = customerBackAction(name, navigation.canGoBack(), params ?? {});
      if (action.kind === "default") return false;
      if (action.kind === "dismissTo") router.dismissTo(action.href as Href);
      else router.replace(action.href as Href);
      return true;
    });
    return () => sub.remove();
  }, [name, navigation, params]);

  const bar = showBar && !keyboard;
  return (
    <View style={s.frame}>
      <SafeAreaInsetsContext.Provider value={bar ? { ...insets, bottom: 0 } : insets}>
        <View style={s.frame}>{children}</View>
      </SafeAreaInsetsContext.Provider>
      {bar ? <CustomerBottomBar active={customerTabForRoute(name)} /> : null}
    </View>
  );
}

const s = StyleSheet.create({
  frame: { flex: 1 },
  bar: { flexDirection: "row" },
  tab: { flex: 1, alignItems: "center", justifyContent: "flex-start", padding: 5 },
  pressed: { opacity: 0.7 },
  iconWrap: { alignItems: "center", justifyContent: "center", height: 28 },
  indicator: { position: "absolute", top: -9, width: 36, height: 3, borderRadius: 2, backgroundColor: "transparent" },
  indicatorOn: { backgroundColor: colors.gold500 },
});
