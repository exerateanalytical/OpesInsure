import React, { useEffect, useState } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { WalletApi } from "@/api/client";
export default function Address() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [x, setX] = useState({
    recipient_name: "",
    phone_e164: "",
    address_line: "",
    city: "",
  });
  useEffect(() => {
    WalletApi.delivery(id).then((d) =>
      setX({
        recipient_name: d.recipient_name,
        phone_e164: d.phone_e164,
        address_line: d.address_line,
        city: d.city,
      }),
    );
  }, [id]);
  return (
    <Screen>
      <AppHeader title="Delivery address" back />
      <Card>
        {Object.entries(x).map(([k, v]) => (
          <TextField
            key={k}
            label={k.replaceAll("_", " ")}
            value={v}
            onChangeText={(t) => setX({ ...x, [k]: t })}
          />
        ))}
        <Button
          label="Save delivery address"
          onPress={async () => {
            await WalletApi.updateAddress(id, x);
            router.back();
          }}
        />
      </Card>
    </Screen>
  );
}
