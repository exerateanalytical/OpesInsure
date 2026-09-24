import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import Svg, { Path } from 'react-native-svg';
import { colors, type } from '@/theme/tokens';

export function BrandMark({ compact = false, inverse = false }: { compact?: boolean; inverse?: boolean }) {
  const ink = inverse ? colors.white : colors.navy950;
  return <View style={styles.row}><View style={styles.mark}><Svg width={28} height={32} viewBox="0 0 28 32"><Path d="M14 1.5 25 5.6v8.6c0 7.2-4.4 12.9-11 16.3C7.4 27.1 3 21.4 3 14.2V5.6L14 1.5Z" fill={colors.blue600}/><Path d="m8.2 15.5 3.6 3.5 8-8" fill="none" stroke={colors.white} strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round"/></Svg></View>{!compact && <Text style={[styles.name,{color:ink}]}>Opes<Text style={styles.blue}>Insure</Text></Text>}</View>;
}
const styles=StyleSheet.create({row:{flexDirection:'row',alignItems:'center',gap:10},mark:{width:36,height:36,alignItems:'center',justifyContent:'center'},name:{...type.cardTitle,fontSize:20},blue:{color:colors.blue600}});
