import React, { useCallback, useEffect, useState } from "react";
import { EmptyState, ErrorState, LoadingState } from "@/components/StatePanel";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { AppHeader, Button, Card, Screen } from "@/components/ui";
import { DisclosureApi, DisclosureSession } from "@/api/client";
import { colors, radius, space, type } from "@/theme/tokens";
export default function Questions() {
  const { proposalId = "" } = useLocalSearchParams<{
    proposalId?: string;
  }>();
  const [s, setS] = useState<DisclosureSession>();
  const [a, setA] = useState<Record<string, boolean | string>>({});
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const load = useCallback(async () => {
    if (!proposalId) {
      setLoading(false);
      return;
    }
    setLoading(true);
    setFailed(false);
    try {
      const x = await DisclosureApi.session(proposalId);
      setS(x);
      setA(
        Object.fromEntries(x.questions.map((q) => [q.id, q.answer ?? false])),
      );
    } catch {
      setFailed(true);
    } finally {
      setLoading(false);
    }
  }, [proposalId]);
  useEffect(() => {
    void load();
  }, [load]);
  return (
    <Screen>
      <AppHeader
        title="Risk disclosure"
        subtitle="Accurate answers protect your claim"
        back
      />
      {!proposalId ? (
        <EmptyState
          title="No proposal selected"
          message="Choose an offer first; disclosure questions belong to a specific proposal."
          action="Start a quote"
          onPress={() => router.replace("/quote/product")}
        />
      ) : loading ? (
        <LoadingState label="Loading disclosure questions…" />
      ) : failed ? (
        <ErrorState onRetry={() => void load()} />
      ) : null}
      {s?.questions.map((q) => (
        <Card key={q.id}>
          <Text style={st.q}>{q.label}</Text>
          <View style={st.choices}>
            {[true, false].map((v) => (
              <Pressable
                key={String(v)}
                accessibilityRole="radio"
                accessibilityState={{ selected: a[q.id] === v }}
                onPress={() => setA({ ...a, [q.id]: v })}
                style={[st.choice, a[q.id] === v && st.selected]}
              >
                <Text style={st.choiceText}>{v ? "Yes" : "No"}</Text>
              </Pressable>
            ))}
          </View>
        </Card>
      ))}
      {s ? (
        <Button
          label="Review underwriting result"
          loading={busy}
          onPress={async () => {
            if (busy) return;
            setBusy(true);
            setError(null);
            try {
              await DisclosureApi.saveAnswers(proposalId, a);
              const x = await DisclosureApi.submit(proposalId);
              // Straight-through proposals become payable; flagged ones wait
              // for an underwriter; others need documents. The hub routes each.
              router.replace(
                x.status === "REFERRED"
                  ? { pathname: "/quote/referral", params: { proposalId } }
                  : { pathname: "/proposals/[id]", params: { id: proposalId } },
              );
            } catch {
              setError("Your answers could not be submitted. Try again.");
            } finally {
              setBusy(false);
            }
          }}
        />
      ) : null}
      {error ? (
        <Text accessibilityRole="alert" style={st.error}>
          {error}
        </Text>
      ) : null}
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
  choiceText: { ...type.label, color: colors.navy950 },
  error: { ...type.meta, color: colors.dangerText },
  selected: { backgroundColor: colors.blue50, borderColor: colors.blue600 },
});
