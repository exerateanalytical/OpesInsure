import React, { useEffect, useState } from "react";
import { Text } from "react-native";
import { useLocalSearchParams } from "expo-router";
import * as DocumentPicker from "expo-document-picker";
import {
  AppHeader,
  Button,
  Card,
  Screen,
  StatusChip,
  TextField,
} from "@/components/ui";
import { SupportApi, SupportCase } from "@/api/client";
export default function SupportDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [x, setX] = useState<SupportCase>();
  const [m, setM] = useState("");
  useEffect(() => {
    SupportApi.show(id).then(setX);
  }, [id]);
  const attach = async () => {
    const p = await DocumentPicker.getDocumentAsync({
      type: ["image/*", "application/pdf"],
    });
    if (!p.canceled && p.assets[0]) {
      const f = new FormData();
      f.append("attachment", p.assets[0] as any);
      setX(await SupportApi.upload(id, f));
    }
  };
  return (
    <Screen>
      <AppHeader title={x?.reference ?? "Support case"} back />
      <Card>
        <StatusChip label={x?.status ?? "LOADING"} tone="info" />
        <Text>{x?.subject}</Text>
        <Text>{x?.description}</Text>
      </Card>
      {x?.messages.map((v) => (
        <Card key={v.id}>
          <Text>{v.sender === "CUSTOMER" ? "You" : "OpesInsure support"}</Text>
          <Text>{v.body}</Text>
          <Text>{v.created_at}</Text>
        </Card>
      ))}
      <Card>
        <TextField
          label="Reply securely"
          multiline
          value={m}
          onChangeText={setM}
        />
        <Button
          label="Send reply"
          disabled={!m.trim()}
          onPress={async () => {
            setX(await SupportApi.reply(id, m));
            setM("");
          }}
        />
        <Button
          label="Attach photo or PDF"
          variant="secondary"
          onPress={attach}
        />
      </Card>
    </Screen>
  );
}
