import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { router } from 'expo-router';
import { ShieldCheck } from 'lucide-react-native';
import { Button, Screen } from '@/components/ui';
import { BrandMark } from '@/components/BrandMark';
import { colors, radius, space, type } from '@/theme/tokens';

export default function SplashTwo() {
  return <Screen scroll={false} style={styles.page}><View style={styles.top}><BrandMark/><View style={styles.heroIcon}><ShieldCheck size={52} color={colors.blue600}/></View><Text style={styles.title}>Protection that moves with you.</Text><Text style={styles.subtitle}>Compare trusted insurers, buy securely and keep every policy in one clear place.</Text></View><View style={styles.bottom}><Button label="Get started" onPress={() => router.replace('/(auth)/sign-in')}/><Text style={styles.powered}>Powered by <Text style={styles.strong}>Opesware Technologies</Text></Text><Text style={styles.expertise}>African engineering · Azure cloud expertise</Text></View></Screen>;
}
const styles=StyleSheet.create({page:{flex:1,justifyContent:'space-between',paddingVertical:space.x6},top:{gap:space.x6},heroIcon:{width:104,height:104,borderRadius:radius.feature,backgroundColor:colors.blue50,alignItems:'center',justifyContent:'center',marginTop:space.x8},title:{...type.display,color:colors.navy950},subtitle:{...type.bodyLarge,color:colors.neutral600},bottom:{gap:space.x3},powered:{...type.meta,color:colors.neutral600,textAlign:'center'},strong:{color:colors.navy950,fontFamily:'Inter_600SemiBold'},expertise:{...type.caption,color:colors.neutral500,textAlign:'center'}});
