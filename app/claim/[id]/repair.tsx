import React, { useEffect, useState } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { Text } from "react-native";
import {
  AppHeader,
  Button,
  Card,
  Money,
  Screen,
  StatusChip,
} from "@/components/ui";
import { ClaimRepair, ClaimsCompletionApi } from "@/api/client";
export default function Repair() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [x, setX] = useState<ClaimRepair>();
  useEffect(() => {
    ClaimsCompletionApi.repair(id).then(setX);
  }, [id]);
  return (
    <Screen>
      <AppHeader title="Repair assessment" back />
      <Card feature>
        <StatusChip label={x?.status ?? "LOADING"} tone="warning" />
        <Text>{x?.garage_name}</Text>
        {x?.estimate_minor != null ? (
          <>
            <Text>Garage estimate</Text>
            <Money amount={x.estimate_minor / 100} />
          </>
        ) : null}
        {x?.approved_minor != null ? (
          <>
            <Text>Insurer-approved repair</Text>
            <Money amount={x.approved_minor / 100} />
          </>
        ) : null}
        {x?.deductible_minor != null ? (
          <Text>
            Policy deductible:{" "}
            {new Intl.NumberFormat("fr-CM").format(x.deductible_minor / 100)}{" "}
            FCFA
          </Text>
        ) : null}
        {x?.authorization_reference ? (
          <Text>Authorization: {x.authorization_reference}</Text>
        ) : null}
      </Card>
      <Button
        label="View settlement offer"
        disabled={!x}
        onPress={() => router.push(`/claim/${id}/settlement`)}
      />
    </Screen>
  );
}
