import React, { useEffect, useRef, useState } from "react";
import {
  NativeScrollEvent,
  NativeSyntheticEvent,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  useWindowDimensions,
  View,
} from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";
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
import { authColors, authSpace, authType } from "@/theme/tokens";
import { useTranslation } from "@/i18n";
import { Preferences } from "@/store/preferences";

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
  const { width } = useWindowDimensions();
  const [page, setPage] = useState(0);
  const scrollRef = useRef<ScrollView>(null);
  const last = page === slides.length - 1;
  const pageRef = useRef(0);
  pageRef.current = page;

  // Rotation / split-screen / foldables change the width: keep the pager on
  // the same slide instead of stranding it between two pages.
  useEffect(() => {
    scrollRef.current?.scrollTo({ x: pageRef.current * width, animated: false });
  }, [width]);

  const onScrollEnd = (e: NativeSyntheticEvent<NativeScrollEvent>) => {
    setPage(Math.round(e.nativeEvent.contentOffset.x / width));
  };

  const goTo = (index: number) => {
    scrollRef.current?.scrollTo({ x: index * width, animated: true });
    setPage(index);
  };

  return (
    <SafeAreaView edges={["top", "bottom"]} style={styles.safe}>
      <Pressable
        accessibilityRole="button"
        style={styles.skip}
        accessibilityLabel={t("skip")}
        onPress={() => leave("/(auth)/sign-in")}
      >
        <Text style={styles.skipText}>{t("skip")}</Text>
      </Pressable>
      <ScrollView
        ref={scrollRef}
        horizontal
        pagingEnabled
        showsHorizontalScrollIndicator={false}
        onMomentumScrollEnd={onScrollEnd}
        style={styles.pager}
      >
        {slides.map((slide) => (
          <ScrollView
            key={slide.key}
            style={{ width }}
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
            <PaginationDots count={slides.length} active={page} />
            <OnboardingFooter tagline={slide.footer} />
          </ScrollView>
        ))}
      </ScrollView>
      <View style={styles.actions}>
        {last ? (
          <>
            <AuthPrimaryButton
              label={t("getStarted")}
              icon={ArrowRight}
              onPress={() => leave("/(auth)/sign-up")}
            />
            <AuthSecondaryButton label={t("haveAccountSignIn")} onPress={() => leave("/(auth)/sign-in")} />
          </>
        ) : (
          <AuthPrimaryButton label={t("next")} icon={ArrowRight} onPress={() => goTo(page + 1)} />
        )}
        <View style={styles.links}>
          <Pressable accessibilityRole="button" hitSlop={8} onPress={() => router.push("/verify")}>
            <Text style={styles.link}>{t("verifyCertificate")}</Text>
          </Pressable>
          <Pressable accessibilityRole="button" hitSlop={8} onPress={() => router.push("/(auth)/invitation")}>
            <Text style={styles.link}>{t("partnersJoin")}</Text>
          </Pressable>
        </View>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: authColors.white },
  pager: { flex: 1 },
  skip: {
    position: "absolute",
    right: authSpace[4],
    top: authSpace[4],
    zIndex: 10,
    paddingHorizontal: authSpace[3],
    paddingVertical: authSpace[1],
  },
  skipText: { ...authType.label, color: authColors.slate500 },
  slideContent: { paddingHorizontal: authSpace[5], paddingBottom: authSpace[4] },
  copyBlock: { alignItems: "center", gap: authSpace[2], marginTop: authSpace[6], marginBottom: authSpace[5] },
  headingTop: { ...authType.h1, fontSize: 30, lineHeight: 36, color: authColors.navy950, textAlign: "center" },
  headingBottom: { ...authType.h1, fontSize: 30, lineHeight: 36, color: authColors.blue500, textAlign: "center" },
  subheading: {
    ...authType.body,
    color: authColors.textSecondary,
    textAlign: "center",
    marginTop: authSpace[2],
  },
  links: { flexDirection: "row", justifyContent: "space-between", paddingVertical: authSpace[2] },
  link: { ...authType.label, fontSize: 13, color: authColors.blue500 },
  actions: { paddingHorizontal: authSpace[5], paddingTop: authSpace[2], gap: authSpace[2] },
});
