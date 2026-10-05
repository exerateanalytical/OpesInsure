import React, { useEffect } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { Screen } from "@/components/ui";
import { BrandHeader } from "@/components/design";
import { ErrorState, LoadingState } from "@/components/StatePanel";
import { RefundsApi } from "@/api/customerFlows";
import { useLoad } from "@/hooks/useLoad";
import { useTranslation } from "@/i18n";

/**
 * Refund notification deep link (/refunds/{id}, LaunchNotificationRouter::refund): the
 * refund is shown on its payment, so this resolves the payment (GET mobile/refunds/{id},
 * own scope) and replaces itself with payments/[id].
 */
export default function RefundLink() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t } = useTranslation();
  const q = useLoad(() => RefundsApi.show(id), [id]);
  const paymentId = q.data?.payment_id;
  useEffect(() => {
    if (paymentId) router.replace({ pathname: "/payments/[id]", params: { id: paymentId } });
  }, [paymentId]);
  return (
    <Screen>
      <BrandHeader title={t("rfListTitle")} back />
      {q.error ? <ErrorState error={q.error} onRetry={() => void q.reload()} /> : <LoadingState label={t("loading")} />}
    </Screen>
  );
}
