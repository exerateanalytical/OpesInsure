import React, { useEffect } from 'react';
import { Stack } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import { Inter_400Regular, Inter_500Medium, Inter_600SemiBold, Inter_700Bold, useFonts } from '@expo-google-fonts/inter';
import { colors } from '@/theme/tokens';
import { roleToPortal, useSession } from '@/store/session';
import { AppRuntime } from '@/components/AppRuntime';

SplashScreen.preventAutoHideAsync();

export default function RootLayout() {
  const [loaded] = useFonts({ Inter_400Regular, Inter_500Medium, Inter_600SemiBold, Inter_700Bold });
  const hydrate=useSession(s=>s.hydrate);
  const status=useSession(s=>s.status);
  const workspace=useSession(s=>s.activeWorkspace);
  useEffect(()=>{void hydrate();},[hydrate]);
  useEffect(() => { if (loaded) SplashScreen.hideAsync(); }, [loaded]);
  if (!loaded) return null;
  const customer=status==='authenticated'&&roleToPortal(workspace?.role_code??'')==='customer';
  const partner=status==='authenticated'&&!!workspace&&roleToPortal(workspace.role_code)!=='customer';
  return <AppRuntime><StatusBar style="dark"/><Stack screenOptions={{ headerShown:false, contentStyle:{backgroundColor:colors.neutral50}, animation:'slide_from_right' }}>
    <Stack.Protected guard={customer}>
      <Stack.Screen name="quote/product"/><Stack.Screen name="quote/risk"/><Stack.Screen name="quote/offers"/><Stack.Screen name="quote/disclosure"/>
      <Stack.Screen name="checkout"/><Stack.Screen name="payment"/><Stack.Screen name="confirmation"/><Stack.Screen name="policy/[id]"/><Stack.Screen name="policy/[id]/service"/><Stack.Screen name="policy/[id]/renew"/><Stack.Screen name="claim/new"/><Stack.Screen name="claim/[id]"/><Stack.Screen name="claim/[id]/evidence"/><Stack.Screen name="claim/[id]/appeal"/>
      <Stack.Screen name="account/profile"/><Stack.Screen name="account/language"/><Stack.Screen name="account/devices"/><Stack.Screen name="account/notifications"/><Stack.Screen name="account/security"/>
    </Stack.Protected>
    <Stack.Protected guard={partner}>
      <Stack.Screen name="workspace/[role]"/><Stack.Screen name="workspace/[role]/module/[module]"/>
    </Stack.Protected>
  </Stack></AppRuntime>;
}
