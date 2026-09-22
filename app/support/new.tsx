import React, { useState } from "react";
import { router } from "expo-router";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { SupportApi } from "@/api/client";
import { colors, radius, space, type } from "@/theme/tokens";
const categories = [
  "GENERAL_SUPPORT",
  "PAYMENT",
  "POLICY_DOCUMENT",
  "CLAIM",
  "DELIVERY",
  "FORMAL_COMPLAINT",
  "REPORT_FRAUD",
];
export default function NewSupport() {
  const [category, setCategory] = useState(categories[0]!);
  const [subject, setSubject] = useState("");
  const [description, setDescription] = useState("");
  return (
    <Screen>
      <AppHeader
        title="New support case"
        subtitle="Never include a PIN, password or mobile-money code"
        back
      />
      <Card>
        <Text style={s.label}>Category</Text>
        <View style={s.wrap}>
          {categories.map((x) => (
            <Pressable
              key={x}
              style={[s.option, category === x && s.selected]}
              onPress={() => setCategory(x)}
            >
              <Text>{x.replaceAll("_", " ")}</Text>
            </Pressable>
          ))}
        </View>
        <TextField label="Subject" value={subject} onChangeText={setSubject} />
        <TextField
          label="Describe what happened"
          multiline
          value={description}
          onChangeText={setDescription}
        />
        <Button
          label={
            category === "FORMAL_COMPLAINT"
              ? "Submit formal complaint"
              : "Create support case"
          }
          disabled={subject.trim().length < 4 || description.trim().length < 15}
          onPress={async () => {
            const x = await SupportApi.create({
              category,
              subject,
              description,
            });
            router.replace(`/support/${x.id}`);
          }}
        />
      </Card>
    </Screen>
  );
}
const s = StyleSheet.create({
  label: { ...type.label, color: colors.navy950 },
  wrap: { gap: space.x2 },
  option: {
    minHeight: 44,
    padding: space.x3,
    borderWidth: 1,
    borderColor: colors.neutral300,
    borderRadius: radius.control,
    justifyContent: "center",
  },
  selected: { borderColor: colors.blue600, backgroundColor: colors.blue50 },
});
