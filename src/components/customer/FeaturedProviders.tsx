import React, { useCallback, useEffect, useRef, useState } from "react";
import {
  AccessibilityInfo,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
  useWindowDimensions,
  type NativeScrollEvent,
  type NativeSyntheticEvent,
} from "react-native";
import { router, useFocusEffect } from "expo-router";
import { CONTENT_MAX_WIDTH, ripple } from "@/components/ui";
import { InstitutionMark, institutionLogo } from "@/components/InstitutionMark";
import { Star } from "lucide-react-native";
import type { Institution } from "@/api/extra";
import { isFeaturedBroker } from "@/lib/institutions";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

const GAP = space.x3;
const PER_VIEW = 2;
const AUTO_MS = 4000;
const RESUME_MS = 6000;
const CARD_HEIGHT = 148;

/** Featured insurers or licensed brokers (kind): a 2-up paging carousel that auto-advances (pauses on touch,
 * off-focus and under reduce-motion). Identical fixed-size cards, no arrows. */
export function FeaturedProviders({ providers, kind = "insurer" }: { providers: Institution[]; kind?: "insurer" | "broker" }) {
  const { t } = useTranslation();
  const { width } = useWindowDimensions();
  const content = Math.min(width, CONTENT_MAX_WIDTH) - space.x5 * 2;
  const cardWidth = Math.floor((content - GAP) / PER_VIEW);
  const interval = cardWidth + GAP;
  const pages = Math.max(1, Math.ceil(providers.length / PER_VIEW));
  const ref = useRef<ScrollView>(null);
  const [page, setPage] = useState(0);
  const pageRef = useRef(0);
  const touching = useRef(false);
  const resumeAt = useRef(0);
  const [reduceMotion, setReduceMotion] = useState(false);

  useEffect(() => {
    let alive = true;
    AccessibilityInfo.isReduceMotionEnabled()
      .then((v) => alive && setReduceMotion(v))
      .catch(() => undefined);
    const sub = AccessibilityInfo.addEventListener("reduceMotionChanged", setReduceMotion);
    return () => {
      alive = false;
      sub.remove();
    };
  }, []);

  useFocusEffect(
    useCallback(() => {
      if (reduceMotion || pages < 2) return undefined;
      const id = setInterval(() => {
        if (touching.current || Date.now() < resumeAt.current) return;
        const next = (pageRef.current + 1) % pages;
        pageRef.current = next;
        setPage(next);
        ref.current?.scrollTo({ x: next * interval * PER_VIEW, animated: true });
      }, AUTO_MS);
      return () => clearInterval(id);
    }, [reduceMotion, pages, interval]),
  );

  const onScrollEnd = (e: NativeSyntheticEvent<NativeScrollEvent>) => {
    const p = Math.min(pages - 1, Math.max(0, Math.round(e.nativeEvent.contentOffset.x / (interval * PER_VIEW))));
    pageRef.current = p;
    setPage(p);
  };
  const hold = () => {
    touching.current = true;
  };
  const release = () => {
    touching.current = false;
    resumeAt.current = Date.now() + RESUME_MS;
  };

  return (
    <View style={styles.wrap}>
      <ScrollView
        ref={ref}
        horizontal
        showsHorizontalScrollIndicator={false}
        decelerationRate="fast"
        snapToInterval={interval * PER_VIEW}
        snapToAlignment="start"
        disableIntervalMomentum
        contentContainerStyle={styles.row}
        onScrollBeginDrag={hold}
        onScrollEndDrag={release}
        onTouchStart={hold}
        onTouchEnd={release}
        onMomentumScrollEnd={onScrollEnd}
        onScroll={onScrollEnd}
        scrollEventThrottle={64}
      >
        {providers.map((p) => {
          const count = p.products?.length ?? 0;
          const star = kind === "broker" && isFeaturedBroker(p);
          const meta =
            kind === "broker"
              ? star
                ? t("brokerFeatured")
                : p.regulator_number
                  ? t("regulatorNumber", { number: p.regulator_number })
                  : t("broker")
              : count
                ? count === 1
                  ? t("productsCountOne")
                  : t("productsCount", { count })
                : t("insurer");
          return (
            <Pressable
              key={p.id}
              accessibilityRole="button"
              accessibilityLabel={`${p.name}. ${meta}`}
              onPress={() => router.push({ pathname: kind === "broker" ? "/institutions/broker/[id]" : "/institutions/insurer/[id]", params: { id: p.id } })}
              android_ripple={ripple()}
              style={({ pressed }) => [styles.card, { width: cardWidth }, star && styles.starCard, pressed && styles.pressed]}
            >
              <InstitutionMark logoUrl={institutionLogo(p)} initials={p.initials} size={52} />
              <View style={styles.nameBox}>
                <Text style={styles.name} numberOfLines={2}>
                  {p.short_name ?? p.name}
                </Text>
              </View>
              {star ? (
                <View style={styles.star}>
                  <Star size={11} color={colors.navy950} fill={colors.navy950} />
                  <Text style={styles.starText} numberOfLines={1}>{meta}</Text>
                </View>
              ) : (
                <Text style={styles.meta} numberOfLines={1}>
                  {meta}
                </Text>
              )}
            </Pressable>
          );
        })}
      </ScrollView>
      {pages > 1 ? (
        <View style={styles.dots} accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
          {Array.from({ length: pages }, (_, i) => (
            <View key={i} style={[styles.dot, i === page && styles.dotActive]} />
          ))}
        </View>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { gap: space.x2 },
  row: { gap: GAP, paddingVertical: 2 },
  pressed: { opacity: 0.82 },
  card: {
    height: CARD_HEIGHT,
    alignItems: "center",
    justifyContent: "flex-start",
    gap: space.x2,
    padding: space.x3,
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.neutral200,
    borderRadius: radius.feature,
    overflow: "hidden",
  },
  nameBox: { height: 36, justifyContent: "center", alignSelf: "stretch" },
  name: { ...type.label, fontSize: 13, lineHeight: 18, fontFamily: "Inter_600SemiBold", color: colors.navy950, textAlign: "center" },
  meta: { ...type.meta, fontSize: 12, lineHeight: 16, color: colors.neutral600, textAlign: "center" },
  starCard: { borderColor: colors.gold500, borderWidth: 1.5 },
  star: { flexDirection: "row", alignItems: "center", gap: 4, backgroundColor: colors.gold500, borderRadius: radius.pill, paddingHorizontal: space.x2, paddingVertical: 2 },
  starText: { ...type.caption, fontSize: 11, lineHeight: 14, fontFamily: "Inter_700Bold", color: colors.navy950 },
  dots: { flexDirection: "row", justifyContent: "center", gap: 6 },
  dot: { width: 6, height: 6, borderRadius: 3, backgroundColor: colors.neutral200 },
  dotActive: { width: 16, backgroundColor: colors.blue600 },
});
