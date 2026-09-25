import React, { useEffect, useState } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { ErrorState, LoadingState } from "@/components/StatePanel";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { WalletApi } from "@/api/client";
import { useLoad } from "@/hooks/useLoad";
import { useTranslation } from "@/i18n";

type AddressForm = { recipient_name: string; phone_e164: string; address_line: string; city: string };
const FIELDS: (keyof AddressForm)[] = ["recipient_name", "phone_e164", "address_line", "city"];

export default function Address() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td } = useTranslation();
  const { data, loading, error, reload } = useLoad(() => WalletApi.delivery(id), [id]);
  const [x, setX] = useState<AddressForm | null>(null);
  const [busy, setBusy] = useState(false);
  const [saveError, setSaveError] = useState<unknown>(null);
  useEffect(() => {
    if (data)
      setX({
        recipient_name: data.recipient_name ?? "",
        phone_e164: data.phone_e164 ?? "",
        address_line: data.address_line ?? "",
        city: data.city ?? "",
      });
  }, [data]);
  const save = async (form: AddressForm) => {
    setBusy(true);
    setSaveError(null);
    try {
      await WalletApi.updateAddress(id, form);
      router.back();
    } catch (e) {
      setSaveError(e);
    } finally {
      setBusy(false);
    }
  };
  return (
    <Screen>
      <AppHeader title={t("addrTitle")} back />
      {!x ? (
        loading ? (
          <LoadingState label={t("addrLoading")} />
        ) : (
          <ErrorState error={error} onRetry={() => void reload()} />
        )
      ) : (
        <Card>
          {FIELDS.map((k) => (
            <TextField
              key={k}
              label={td(`addr_${k}`, k)}
              value={x[k]}
              keyboardType={k === "phone_e164" ? "phone-pad" : "default"}
              onChangeText={(v) => setX({ ...x, [k]: v })}
            />
          ))}
          <Button label={t("addrSave")} loading={busy} onPress={() => void save(x)} />
          {saveError ? <ErrorCard error={saveError} fallback={t("errGeneric")} /> : null}
        </Card>
      )}
    </Screen>
  );
}
