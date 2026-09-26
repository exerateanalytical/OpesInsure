import React, { useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { ChevronDown, ChevronUp, CircleHelp, MessageCircle } from "lucide-react-native";
import { Button, Card, ripple, Screen } from "@/components/ui";
import { BrandHeader, SectionHeading, TintedIcon } from "@/components/design";
import { SearchBar } from "@/components/SearchBar";
import { SupportContactList } from "@/components/auth/SupportContacts";
import { useTranslation } from "@/i18n";
import type { CopyKey } from "@/i18n/strings";
import { matchesQuery } from "@/lib/customerLogic";
import { colors, radius, space, type } from "@/theme/tokens";

/** Curated help centre (static, bilingual). Topics group the questions. */
const TOPICS: { title: CopyKey; items: [CopyKey, CopyKey][] }[] = [
  { title: "faqTopicBuying", items: [["faqQ1", "faqA1"], ["faqQ2", "faqA2"], ["faqQ3", "faqA3"]] },
  { title: "faqTopicPayments", items: [["faqQ4", "faqA4"], ["faqQ5", "faqA5"]] },
  { title: "faqTopicPolicies", items: [["faqQ6", "faqA6"], ["faqQ7", "faqA7"]] },
  { title: "faqTopicClaims", items: [["faqQ8", "faqA8"], ["faqQ9", "faqA9"], ["faqQ10", "faqA10"]] },
  { title: "faqTopicAccount", items: [["faqQ11", "faqA11"], ["faqQ12", "faqA12"]] },
];

export default function Faq() {
  const { t } = useTranslation();
  const [open, setOpen] = useState<string | null>(null);
  const [query, setQuery] = useState("");
  const topics = TOPICS.map((topic) => ({
    ...topic,
    items: topic.items.filter(([q, a]) => matchesQuery(query, t(q), t(a))),
  })).filter((topic) => topic.items.length);
  return (
    <Screen>
      <BrandHeader title={t("faqTitle")} subtitle={t("faqSubtitle")} back right={null} />
      <SearchBar value={query} onChangeText={setQuery} label={t("faqSearch")} placeholder={t("faqSearch")} clearLabel={t("clearSearch")} />
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
    </Screen>
  );
}
const styles = StyleSheet.create({
  pressed: { opacity: 0.85 },
  topic: { gap: space.x3 },
  item: { borderRadius: radius.feature, padding: space.x3, gap: 0 },
  itemOpen: { borderColor: colors.blue100 },
  question: { minHeight: 48, flexDirection: "row", alignItems: "center", gap: space.x3 },
  q: { ...type.label, color: colors.navy950, flex: 1 },
  answer: { ...type.body, color: colors.neutral700, paddingTop: space.x3, paddingLeft: 36 + space.x3 },
  body: { ...type.body, color: colors.neutral700 },
  help: { borderRadius: radius.feature },
});
