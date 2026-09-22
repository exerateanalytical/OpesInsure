import React, { useEffect, useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { AppHeader, Button, Card, Screen } from "@/components/ui";
import { DisclosureApi, DisclosureSession } from "@/api/client";
import { colors, radius, space, type } from "@/theme/tokens";
export default function Questions() {
  const { proposalId = "proposal-001" } = useLocalSearchParams<{
    proposalId: string;
  }>();
  const [s, setS] = useState<DisclosureSession>();
  const [a, setA] = useState<Record<string, boolean | string>>({});
  useEffect(() => {
    DisclosureApi.session(proposalId).then((x) => {
      setS(x);
      setA(
        Object.fromEntries(x.questions.map((q) => [q.id, q.answer ?? false])),
      );
    });
  }, [proposalId]);
  return (
    <Screen>
      <AppHeader
        title="Risk disclosure"
        subtitle="Accurate answers protect your claim"
        back
      />
      {s?.questions.map((q) => (
        <Card key={q.id}>
          <Text style={st.q}>{q.label}</Text>
          <View style={st.choices}>
            {[true, false].map((v) => (
              <Pressable
                key={String(v)}
                onPress={() => setA({ ...a, [q.id]: v })}
                style={[st.choice, a[q.id] === v && st.selected]}
              >
                <Text>{v ? "Yes" : "No"}</Text>
              </Pressable>
            ))}
          </View>
        </Card>
      ))}
      <Button
        label="Review underwriting result"
        onPress={async () => {
          await DisclosureApi.saveAnswers(proposalId, a);
          const x = await DisclosureApi.submit(proposalId);
          router.push(
            x.status === "REFERRED"
              ? `/quote/referral?proposalId=${proposalId}`
              : `/quote/terms?proposalId=${proposalId}`,
          );
        }}
      />
    </Screen>
  );
}
const st = StyleSheet.create({
  q: { ...type.cardTitle, color: colors.navy950 },
  choices: { flexDirection: "row", gap: space.x3 },
  choice: {
    flex: 1,
    minHeight: 48,
    alignItems: "center",
    justifyContent: "center",
    borderWidth: 1,
    borderColor: colors.neutral300,
    borderRadius: radius.control,
  },
  selected: { backgroundColor: colors.blue50, borderColor: colors.blue600 },
});
