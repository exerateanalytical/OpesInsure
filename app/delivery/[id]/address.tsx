import React, { useEffect, useState } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { MapPin } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { CtaBar } from "@/components/design";
import { ErrorState, LoadingState } from "@/components/StatePanel";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { ReviewFooter, ReviewIntro, ReviewRows, ReviewSection } from "@/components/review/ReviewSummary";
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
  // The address is shown read-only for a last check before it is saved.
  const [reviewing, setReviewing] = useState(false);
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
      await WalletApi.updateAddress(
        id,
        { recipient_name: form.recipient_name.trim(), phone: form.phone_e164.trim(), line1: form.address_line.trim(), city: form.city.trim() },
        data?.version,
      );
      router.back();
    } catch (e) {
      setSaveError(e);
    } finally {
      setBusy(false);
    }
  };
  return (
    <Screen
      footer={
        !x ? undefined : reviewing ? (
          <ReviewFooter label={t("addrSave")} loading={busy} onConfirm={() => void save(x)} onBack={() => setReviewing(false)} />
        ) : (
          <CtaBar>
            <Button
              label={t("reviewBeforeSave")}
              onPress={() => {
                setSaveError(null);
                setReviewing(true);
              }}
            />
          </CtaBar>
        )
      }
    >
      <AppHeader title={t("addrTitle")} back />
      {!x ? (
        loading ? (
          <LoadingState label={t("addrLoading")} />
        ) : (
          <ErrorState error={error} onRetry={() => void reload()} />
        )
      ) : reviewing ? (
        <>
          <ReviewIntro body={t("reviewSaveIntro")} />
          <ReviewSection icon={MapPin} title={t("addrTitle")} onEdit={() => setReviewing(false)}>
            <ReviewRows rows={FIELDS.map((k) => ({ key: k, label: td(`addr_${k}`, k), value: x[k].trim() || null }))} />
          </ReviewSection>
          {saveError ? <ErrorCard error={saveError} fallback={t("errGeneric")} /> : null}
        </>
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
        </Card>
      )}
    </Screen>
  );
}
