import React, { useCallback, useEffect, useRef, useState } from "react";
import {
  BackHandler,
  LayoutChangeEvent,
  NativeScrollEvent,
  NativeSyntheticEvent,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  useWindowDimensions,
  View,
} from "react-native";
import { SafeAreaView, useSafeAreaInsets } from "react-native-safe-area-context";
import { router } from "expo-router";
import {
  ArrowRight,
  Building2,
  ClipboardCheck,
  FileCheck2,
  Handshake,
  LockKeyhole,
  Search,
  ShieldCheck,
  ShieldPlus,
  UsersRound,
} from "lucide-react-native";
import {
  OnboardingFeatureRow,
  OnboardingFooter,
  OnboardingHero,
  OnboardingNodeGrid,
  PaginationDots,
} from "@/components/onboarding/OnboardingParts";
import { AuthPrimaryButton, AuthSecondaryButton } from "@/components/auth/AuthField";
import { authColors, authSpace, authType, colors } from "@/theme/tokens";
import { useTranslation } from "@/i18n";
import { Preferences } from "@/store/preferences";
import { nextPage, pageFromOffset, previousPage, tapAllowed } from "@/lib/pager";

/** Leaving the slides by any route marks first run as done, so a returning
 * signed-out user lands on sign-in instead of the marketing pager. */
const leave = (to: "/(auth)/sign-in" | "/(auth)/sign-up") => {
  void Preferences.markOnboardingSeen();
  router.replace(to);
};

const buildSlides = (t: ReturnType<typeof useTranslation>["t"]) => [
  {
    key: "compare",
    headingTop: t("welcome1Top"),
    headingBottom: t("welcome1Bottom"),
    subheading: t("welcome1Body"),
    footer: t("welcomeFooter"),
    body: (
      <OnboardingFeatureRow
        items={[
          { icon: ShieldCheck, label: t("welcomeLicensed") },
          { icon: LockKeyhole, label: t("welcomeSecurePayments") },
          { icon: FileCheck2, label: t("welcomeVerifiedProducts") },
        ]}
      />
    ),
  },
  {
    key: "lifecycle",
    headingTop: t("welcome2Top"),
    headingBottom: t("welcome2Bottom"),
    subheading: t("welcome2Body"),
    footer: t("welcomeFooter"),
    body: (
      <OnboardingFeatureRow
        items={[
          { icon: Search, label: t("welcomeCompare"), caption: t("welcomeCompareCaption") },
          { icon: ShieldPlus, label: t("welcomeBuy"), caption: t("welcomeBuyCaption") },
          { icon: ClipboardCheck, label: t("welcomeClaim"), caption: t("welcomeClaimCaption") },
        ]}
      />
    ),
  },
  {
    key: "marketplace",
    headingTop: t("welcome3Top"),
    headingBottom: t("welcome3Bottom"),
    subheading: t("welcome3Body"),
    footer: t("welcomeFooter3"),
    body: (
      <OnboardingNodeGrid
        items={[
          { icon: UsersRound, label: t("audienceIndividuals"), caption: t("audienceIndividualsCaption") },
          { icon: Building2, label: t("audienceBusinesses"), caption: t("audienceBusinessesCaption") },
          { icon: Handshake, label: t("audienceIntermediaries"), caption: t("audienceIntermediariesCaption") },
          { icon: ShieldCheck, label: t("audienceInsurers"), caption: t("audienceInsurersCaption") },
        ]}
      />
    ),
  },
];

export default function Onboarding() {
  const { t } = useTranslation();
  const slides = buildSlides(t);
  const count = slides.length;
  const window = useWindowDimensions();
  const insets = useSafeAreaInsets();
  // Small phones and large system fonts: the action bar keeps 48dp targets
  // and lets labels wrap instead of clipping.
  const compact = window.width < 360 || window.fontScale > 1.2;
  // Page width is the pager's MEASURED width, not the window width: with
  // split-screen, foldables, display cut-outs or a landscape inset the two
  // differ and the pages drifted out of alignment ("Next broke the pager").
  const [measured, setMeasured] = useState(0);
  const width = measured || window.width;
  const [page, setPage] = useState(0);
  const scrollRef = useRef<ScrollView>(null);
  const last = page === count - 1;
  const pageRef = useRef(0);
  pageRef.current = page;
  const lastTap = useRef(0);

  // Rotation / split-screen / foldables change the width: keep the pager on
  // the same slide instead of stranding it between two pages.
  useEffect(() => {
    scrollRef.current?.scrollTo({ x: pageRef.current * width, animated: false });
  }, [width]);

  const onLayout = (e: LayoutChangeEvent) => {
    const w = Math.round(e.nativeEvent.layout.width);
    if (w > 0 && w !== measured) setMeasured(w);
  };

  const onScrollEnd = (e: NativeSyntheticEvent<NativeScrollEvent>) => {
    setPage(pageFromOffset(e.nativeEvent.contentOffset.x, width, count));
  };

  // Every target is clamped to a real slide: rapid taps can never push the
  // pager past the last slide (the old code reached page 3 of 3 and showed
  // "Next" on a blank page).
  const goTo = useCallback(
    (index: number) => {
      scrollRef.current?.scrollTo({ x: index * width, animated: true });
      setPage(index);
    },
    [width],
  );

  /** Debounced primary action: one tap = one step. */
  const primary = (action: () => void) => {
    const now = Date.now();
    if (!tapAllowed(lastTap.current, now)) return;
    lastTap.current = now;
    action();
  };

  // Android back steps back through the slides before leaving the screen.
  useEffect(() => {
    const sub = BackHandler.addEventListener("hardwareBackPress", () => {
      if (pageRef.current === 0) return false;
      goTo(previousPage(pageRef.current, count));
      return true;
    });
    return () => sub.remove();
  }, [goTo, count]);

  return (
    <SafeAreaView edges={["top"]} style={styles.safe}>
      <View style={styles.topBar}>
      <Pressable
        accessibilityRole="button"
        hitSlop={8}
        style={styles.skip}
        accessibilityLabel={t("skip")}
        onPress={() => leave("/(auth)/sign-in")}
      >
        <Text style={styles.skipText}>{t("skip")}</Text>
      </Pressable>
      </View>
      <ScrollView
        ref={scrollRef}
        horizontal
        pagingEnabled
        showsHorizontalScrollIndicator={false}
        onMomentumScrollEnd={onScrollEnd}
        onLayout={onLayout}
        style={styles.pager}
        // Programmatic scrollTo() + a fling in flight must not compete.
        disableIntervalMomentum
      >
        {slides.map((slide) => (
          <ScrollView
            key={slide.key}
            style={{ width }}
            nestedScrollEnabled
            showsVerticalScrollIndicator={false}
            contentContainerStyle={styles.slideContent}
          >
            <OnboardingHero />
            <View style={styles.copyBlock}>
              <Text accessibilityRole="header" style={styles.headingTop}>{slide.headingTop}</Text>
              <Text style={styles.headingBottom}>{slide.headingBottom}</Text>
              <Text style={styles.subheading}>{slide.subheading}</Text>
            </View>
            {slide.body}
            <OnboardingFooter tagline={slide.footer} />
          </ScrollView>
        ))}
      </ScrollView>
      {/* Action bar: pinned above the home indicator / gesture bar. The bottom
          inset is applied here (SafeAreaView only pads the top) so it is never
          counted twice. */}
      <View style={[styles.actions, { paddingBottom: authSpace[2] + insets.bottom }]}>
        <View style={styles.actionsInner}>
          {last ? (
            <>
              <AuthPrimaryButton
                label={t("getStarted")}
                onPress={() => primary(() => leave("/(auth)/sign-up"))}
                icon={ArrowRight}
              />
              <AuthSecondaryButton label={t("haveAccountSignIn")} onPress={() => leave("/(auth)/sign-in")} />
            </>
          ) : (
            <View style={styles.stepRow}>
              <PaginationDots count={count} active={page} />
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={t("next")}
                hitSlop={4}
                onPress={() => primary(() => goTo(nextPage(pageRef.current, count)))}
                style={({ pressed }) => [styles.next, compact && styles.nextCompact, pressed && styles.nextPressed]}
              >
                <Text style={styles.nextLabel}>{t("next")}</Text>
                <ArrowRight size={20} color={colors.white} />
              </Pressable>
            </View>
          )}
          <View style={[styles.links, compact && styles.linksCompact]}>
            <Pressable accessibilityRole="button" hitSlop={8} style={styles.linkHit} onPress={() => router.push("/verify")}>
              <Text style={styles.link}>{t("verifyCertificate")}</Text>
            </Pressable>
            <Pressable
              accessibilityRole="button"
              hitSlop={8}
              style={styles.linkHit}
              onPress={() => router.push("/(auth)/invitation")}
            >
              <Text style={styles.link}>{t("partnersJoin")}</Text>
            </Pressable>
          </View>
        </View>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.neutral50 },
  pager: { flex: 1 },
  // In the layout flow (not absolute): an absolute Skip sat under the
  // status bar on edge-to-edge Android.
  topBar: { flexDirection: "row", justifyContent: "flex-end", paddingHorizontal: authSpace[4], paddingTop: authSpace[1] },
  skip: {
    minHeight: 40,
    justifyContent: "center",
    paddingHorizontal: authSpace[4],
    borderRadius: 999,
    backgroundColor: colors.navy950,
  },
  skipText: { ...authType.label, color: colors.white },
  slideContent: { paddingHorizontal: authSpace[5], paddingBottom: authSpace[4] },
  copyBlock: { alignItems: "center", gap: authSpace[2], marginTop: authSpace[6], marginBottom: authSpace[5] },
  headingTop: { ...authType.h1, fontSize: 28, lineHeight: 34, color: authColors.navy950, textAlign: "center" },
  headingBottom: { ...authType.h1, fontSize: 28, lineHeight: 34, color: colors.terracotta700, textAlign: "center" },
  subheading: {
    ...authType.body,
    color: authColors.textSecondary,
    textAlign: "center",
    marginTop: authSpace[2],
  },
  links: { flexDirection: "row", justifyContent: "space-between", alignItems: "center", gap: authSpace[3] },
  linksCompact: { flexDirection: "column", alignItems: "stretch", gap: 0 },
  linkHit: { minHeight: 44, justifyContent: "center", flexShrink: 1 },
  link: { ...authType.label, fontSize: 13, color: authColors.blue500 },
  actions: {
    paddingHorizontal: authSpace[5],
    paddingTop: authSpace[3],
    paddingBottom: authSpace[2],
    backgroundColor: colors.neutral50,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: colors.neutral200,
  },
  // Tablets: keep the controls a thumb-friendly width instead of edge to edge.
  actionsInner: { width: "100%", maxWidth: 480, alignSelf: "center", gap: authSpace[2] },
  stepRow: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", gap: authSpace[3] },
  next: {
    minHeight: 48,
    minWidth: 120,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: authSpace[2],
    paddingHorizontal: authSpace[5],
    paddingVertical: authSpace[2],
    borderRadius: 999,
    backgroundColor: colors.navy900,
  },
  nextCompact: { minWidth: 104, paddingHorizontal: authSpace[4], flexShrink: 1 },
  nextPressed: { opacity: 0.85 },
  nextLabel: { ...authType.button, color: colors.white, flexShrink: 1 },
});
