import React, { useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { ChevronDown, ChevronUp, MessageCircle } from "lucide-react-native";
import { AppHeader, Button, Card, Screen } from "@/components/ui";
import { SearchBar } from "@/components/SearchBar";
import { SupportContactList } from "@/components/auth/SupportContacts";
import { useTranslation } from "@/i18n";
import type { CopyKey } from "@/i18n/strings";
import { matchesQuery } from "@/lib/customerLogic";
import { colors, space, type } from "@/theme/tokens";

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
      <AppHeader title={t("faqTitle")} subtitle={t("faqSubtitle")} back />
      <SearchBar value={query} onChangeText={setQuery} label={t("faqSearch")} placeholder={t("faqSearch")} clearLabel={t("clearSearch")} />
      {topics.length === 0 ? <Text style={styles.body}>{t("faqNoResults")}</Text> : null}
      {topics.map((topic) => (
        <Card key={topic.title}>
          <Text accessibilityRole="header" style={styles.topic}>{t(topic.title)}</Text>
          {topic.items.map(([q, a]) => {
            const expanded = open === q;
            return (
              <View key={q} style={styles.item}>
                <Pressable
                  accessibilityRole="button"
                  accessibilityState={{ expanded }}
                  onPress={() => setOpen(expanded ? null : q)}
                  style={styles.question}
                >
                  <Text style={styles.q}>{t(q)}</Text>
                  {expanded ? <ChevronUp size={20} color={colors.navy800} /> : <ChevronDown size={20} color={colors.navy800} />}
                </Pressable>
                {expanded ? <Text style={styles.body}>{t(a)}</Text> : null}
              </View>
            );
          })}
        </Card>
      ))}
      <Card>
        <Text style={styles.topic}>{t("faqStillNeedHelp")}</Text>
        <Button label={t("supportNewTicket")} icon={MessageCircle} onPress={() => router.push("/support/new")} />
      </Card>
      <SupportContactList heading={t("talkToUs")} />
    </Screen>
  );
}
const styles = StyleSheet.create({
  topic: { ...type.cardTitle, color: colors.navy950 },
  item: { borderTopWidth: 1, borderTopColor: colors.neutral100, paddingVertical: space.x1, gap: space.x2 },
  question: { minHeight: 48, flexDirection: "row", alignItems: "center", gap: space.x3 },
  q: { ...type.label, color: colors.navy950, flex: 1 },
  body: { ...type.body, color: colors.neutral700, paddingBottom: space.x2 },
});
