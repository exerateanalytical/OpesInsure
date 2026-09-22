import React, { useEffect } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { router } from 'expo-router';
import { BrandMark } from '@/components/BrandMark';
import { colors, space, type } from '@/theme/tokens';
import { roleToPortal, useSession } from '@/store/session';

export default function SplashOne() {
  const status=useSession(s=>s.status);const workspace=useSession(s=>s.activeWorkspace);
  useEffect(() => { if(status==='booting')return;const timer=setTimeout(()=>{if(status==='authenticated'&&workspace){const portal=roleToPortal(workspace.role_code);router.replace(portal==='customer'?'/(customer)/(tabs)':{pathname:'/workspace/[role]',params:{role:portal??'denied'}});}else router.replace('/welcome');},900);return()=>clearTimeout(timer);},[status,workspace]);
  return <LinearGradient colors={[colors.navy950,colors.navy900]} style={styles.page}><View style={styles.center}><BrandMark inverse/><Text style={styles.promise}>Insurance made clear.</Text></View><View style={styles.motif}><View style={[styles.line,{backgroundColor:'#07855B'}]}/><View style={[styles.line,{backgroundColor:'#C9363E'}]}/><View style={[styles.line,{backgroundColor:'#D99100'}]}/></View></LinearGradient>;
}
const styles=StyleSheet.create({page:{flex:1,alignItems:'center',justifyContent:'center'},center:{alignItems:'center',gap:space.x4},promise:{...type.bodyLarge,color:'#DCE3E8'},motif:{position:'absolute',bottom:0,left:0,right:0,flexDirection:'row'},line:{flex:1,height:4}});
