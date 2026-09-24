import React, { useRef, useState } from "react";
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

const slides = [
  {
    key: "compare",
    headingTop: "Compare insurance",
    headingBottom: "with confidence",
    subheading: "Access cover from trusted insurers, brokers and agents in one place.",
    footer: "PEOPLE · PROTECTION · A BRIGHTER TOMORROW",
    body: (
      <OnboardingFeatureRow
        items={[
          { icon: ShieldCheck, label: "Licensed Providers" },
          { icon: LockKeyhole, label: "Secure Payments" },
          { icon: FileCheck2, label: "Verified Products" },
        ]}
      />
    ),
  },
  {
    key: "lifecycle",
    headingTop: "Buy, renew and",
    headingBottom: "claim anywhere",
    subheading:
      "Manage your policies from one app — compare offers, get covered, renew on time, and follow claims with ease.",
    footer: "PEOPLE · PROTECTION · A BRIGHTER TOMORROW",
    body: (
      <OnboardingFeatureRow
        items={[
          { icon: Search, label: "Compare", caption: "Find the right\ncover for you" },
          { icon: ShieldPlus, label: "Buy", caption: "Get covered\nin minutes" },
          { icon: ClipboardCheck, label: "Claim", caption: "Track claims\nwith ease" },
        ]}
      />
    ),
  },
  {
    key: "marketplace",
    headingTop: "One marketplace.",
    headingBottom: "Every insurance player.",
    subheading:
      "Built for customers, insurers, brokers and agents — connecting cover, payments and protection across Africa.",
    footer: "PROTECTION FOR A BRIGHTER TOMORROW.",
    body: (
      <OnboardingNodeGrid
        items={[
          { icon: UsersRound, label: "Individuals & Families", caption: "MORE SECURITY" },
          { icon: Building2, label: "Businesses & Organizations", caption: "GREATER RESILIENCE" },
          { icon: Handshake, label: "Brokers & Agents", caption: "BIGGER OPPORTUNITIES" },
          { icon: ShieldCheck, label: "Insurance Companies", caption: "A STRONGER AFRICA" },
        ]}
      />
    ),
  },
];

export default function Onboarding() {
  const { width } = useWindowDimensions();
  const [page, setPage] = useState(0);
  const scrollRef = useRef<ScrollView>(null);
  const last = page === slides.length - 1;

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
        onPress={() => router.replace("/(auth)/sign-in")}
      >
        <Text style={styles.skipText}>Skip</Text>
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
              label="Get Started"
              icon={ArrowRight}
              onPress={() => router.replace("/(auth)/sign-in")}
            />
            <AuthSecondaryButton label="Create Account" onPress={() => router.push("/(auth)/sign-up")} />
          </>
        ) : (
          <AuthPrimaryButton label="Next" icon={ArrowRight} onPress={() => goTo(page + 1)} />
        )}
        <View style={styles.links}>
          <Pressable accessibilityRole="button" hitSlop={8} onPress={() => router.push("/verify")}>
            <Text style={styles.link}>Verify a certificate</Text>
          </Pressable>
          <Pressable accessibilityRole="button" hitSlop={8} onPress={() => router.push("/(auth)/invitation")}>
            <Text style={styles.link}>Partners: join by invitation</Text>
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
