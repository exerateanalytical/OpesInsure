import React, { useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { CarFront, ChevronDown, ChevronRight, ChevronUp, CircleHelp, CreditCard, FileText, MessageCircle, ShieldCheck, Siren, UserRound } from "lucide-react-native";
import type { LucideIcon } from "lucide-react-native";
import { Button, Card, ripple, Screen } from "@/components/ui";
import { BrandHeader, SectionHeading, TintedIcon, type Tint } from "@/components/design";
import { SearchBar } from "@/components/SearchBar";
import { SupportContactList } from "@/components/auth/SupportContacts";
import { useTranslation } from "@/i18n";
import type { CopyKey } from "@/i18n/strings";
import { matchesQuery } from "@/lib/customerLogic";
import { colors, radius, space, type } from "@/theme/tokens";

const TILE_BG: Record<Tint, string> = { blue: colors.blue50, gold: colors.gold50, red: colors.dangerSoft, green: colors.successSoft, neutral: colors.neutral50, purple: colors.purple50 };
const TILE_FG: Record<Tint, string> = { blue: colors.blue700, gold: colors.navy900, red: colors.danger, green: colors.successText, neutral: colors.neutral700, purple: colors.purple700 };

/** Curated help centre (static, bilingual). Topics group the questions. */
const TOPICS: { title: CopyKey; body: CopyKey; icon: LucideIcon; tint: Tint; items: [CopyKey, CopyKey][] }[] = [
  { title: "faqTopicBuying", body: "faqTileBuying", icon: CarFront, tint: "gold", items: [["faqQ1", "faqA1"], ["faqQ2", "faqA2"], ["faqQ3", "faqA3"]] },
  { title: "faqTopicPayments", body: "faqTilePayments", icon: CreditCard, tint: "red", items: [["faqQ4", "faqA4"], ["faqQ5", "faqA5"]] },
  { title: "faqTopicPolicies", body: "faqTilePolicies", icon: FileText, tint: "blue", items: [["faqQ6", "faqA6"], ["faqQ7", "faqA7"]] },
  { title: "faqTopicClaims", body: "faqTileClaims", icon: ShieldCheck, tint: "green", items: [["faqQ8", "faqA8"], ["faqQ9", "faqA9"], ["faqQ10", "faqA10"]] },
  { title: "faqTopicAccount", body: "faqTileAccount", icon: UserRound, tint: "blue", items: [["faqQ11", "faqA11"], ["faqQ12", "faqA12"]] },
];

export default function Faq() {
  const { t } = useTranslation();
  const [open, setOpen] = useState<string | null>(null);
  const [query, setQuery] = useState("");
  const [topic, setTopic] = useState<CopyKey | null>(null);
  const topics = TOPICS.filter((x) => !topic || x.title === topic).map((topic) => ({
    ...topic,
    items: topic.items.filter(([q, a]) => matchesQuery(query, t(q), t(a))),
  })).filter((topic) => topic.items.length);
  return (
    <Screen>
      <BrandHeader title={t("faqTitle")} subtitle={t("faqSubtitle")} back right={null} />
      <SearchBar value={query} onChangeText={setQuery} label={t("faqSearch")} placeholder={t("faqSearch")} clearLabel={t("clearSearch")} />
      <View style={styles.grid}>
        {TOPICS.map((x) => {
          const on = topic === x.title;
          return (
            <Pressable
              key={x.title}
              accessibilityRole="button"
              accessibilityState={{ selected: on }}
              accessibilityLabel={t(x.title)}
              onPress={() => setTopic(on ? null : x.title)}
              android_ripple={ripple()}
              style={({ pressed }) => [styles.tile, { backgroundColor: TILE_BG[x.tint] }, on && styles.tileOn, pressed && styles.pressed]}
            >
              <View style={styles.tileHead}>
                <x.icon size={24} color={TILE_FG[x.tint]} strokeWidth={2} />
                <Text style={[styles.tileTitle, styles.flex]}>{t(x.title)}</Text>
                <ChevronRight size={18} color={colors.navy800} />
              </View>
              <Text style={styles.tileBody}>{t(x.body)}</Text>
            </Pressable>
          );
        })}
      </View>
      {topic ? <Button label={t("faqAllTopics")} variant="tertiary" onPress={() => setTopic(null)} /> : null}
      {topics.length === 0 ? <Text style={styles.body}>{t("faqNoResults")}</Text> : null}
      {topics.map((topic) => (
        <View key={topic.title} style={styles.topic}>
          <SectionHeading title={t(topic.title)} />
          {topic.items.map(([q, a]) => {
            const expanded = open === q;
            return (
              <Card key={q} style={[styles.item, expanded && styles.itemOpen]}>
                <Pressable
                  accessibilityRole="button"
                  accessibilityState={{ expanded }}
                  onPress={() => setOpen(expanded ? null : q)}
                  android_ripple={ripple()}
                  style={({ pressed }) => [styles.question, pressed && styles.pressed]}
                >
                  <TintedIcon icon={CircleHelp} tint={expanded ? "blue" : "neutral"} size={36} />
                  <Text style={styles.q}>{t(q)}</Text>
                  {expanded ? <ChevronUp size={20} color={colors.blue600} /> : <ChevronDown size={20} color={colors.navy800} />}
                </Pressable>
                {expanded ? <Text style={styles.answer}>{t(a)}</Text> : null}
              </Card>
            );
          })}
        </View>
      ))}
      <Card style={styles.help}>
        <SectionHeading title={t("faqStillNeedHelp")} icon={MessageCircle} />
        <Button label={t("supportNewTicket")} icon={MessageCircle} onPress={() => router.push("/support/new")} />
      </Card>
      <SupportContactList heading={t("talkToUs")} />
      <Pressable accessibilityRole="button" onPress={() => router.push("/claim/emergency" as never)} android_ripple={ripple()} style={({ pressed }) => [styles.urgent, pressed && styles.pressed]}>
        <TintedIcon icon={Siren} tint="red" size={48} />
        <View style={styles.flex}>
          <Text style={styles.urgentKicker}>{t("faqUrgentKicker")}</Text>
          <Text style={styles.urgentTitle}>{t("faqUrgentTitle")}</Text>
          <Text style={styles.urgentBody}>{t("faqUrgentBody")}</Text>
        </View>
        <ChevronRight size={22} color={colors.gold500} />
      </Pressable>
    </Screen>
  );
}
const styles = StyleSheet.create({
  pressed: { opacity: 0.85 },
  flex: { flex: 1, gap: 2 },
  grid: { flexDirection: "row", flexWrap: "wrap", gap: space.x3 },
  tile: { flexGrow: 1, flexBasis: 150, minHeight: 48, paddingVertical: space.x3, paddingHorizontal: space.x3, gap: space.x1, borderRadius: radius.feature, borderWidth: 1, borderColor: "transparent" },
  tileHead: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  tileOn: { borderColor: colors.blue600 },
  tileTitle: { ...type.label, fontSize: 15, lineHeight: 20, color: colors.navy950 },
  tileBody: { ...type.meta, color: colors.neutral600 },
  urgent: { flexDirection: "row", alignItems: "center", gap: space.x3, padding: space.x4, borderRadius: radius.feature, backgroundColor: colors.navy950 },
  urgentKicker: { ...type.caption, color: colors.gold500, letterSpacing: 1, textTransform: "uppercase" },
  urgentTitle: { ...type.label, fontSize: 17, color: colors.white },
  urgentBody: { ...type.meta, color: colors.neutral200 },
  topic: { gap: space.x3 },
  item: { borderRadius: radius.feature, padding: space.x3, gap: 0 },
  itemOpen: { borderColor: colors.blue100 },
  question: { minHeight: 48, flexDirection: "row", alignItems: "center", gap: space.x3 },
  q: { ...type.label, color: colors.navy950, flex: 1 },
  answer: { ...type.body, color: colors.neutral700, paddingTop: space.x3, paddingLeft: 36 + space.x3 },
  body: { ...type.body, color: colors.neutral700 },
  help: { borderRadius: radius.feature },
});
