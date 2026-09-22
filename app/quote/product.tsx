import React from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { router } from 'expo-router';
import { Car, ChevronRight, HeartPulse, Home, Plane, ShieldPlus } from 'lucide-react-native';
import { AppHeader, Card, Screen } from '@/components/ui';
import { colors, radius, space, type } from '@/theme/tokens';
import { useInsurance } from '@/store/insurance';
const products=[['motor','Motor insurance','Car, motorcycle or commercial vehicle',Car],['health','Health cover','Individual, family or employee healthcare',HeartPulse],['travel','Travel insurance','Medical assistance and trip protection',Plane],['home','Home insurance','Building, contents and liability',Home],['life','Life protection','Family protection, savings or education',ShieldPlus]] as const;
export default function Product(){const setProduct=useInsurance(s=>s.setProduct);return <Screen><AppHeader title="Choose your cover" subtitle="Step 1 of 5 · You can save and return" back/>{products.map(([id,title,subtitle,Icon])=><Pressable accessibilityRole="button" key={id} onPress={()=>{setProduct(id);router.push({pathname:'/quote/risk',params:{product:id}})}}><Card><View style={styles.row}><View style={styles.icon}><Icon size={22} color={colors.blue600}/></View><View style={styles.copy}><Text style={styles.title}>{title}</Text><Text style={styles.subtitle}>{subtitle}</Text></View><ChevronRight size={20} color={colors.neutral500}/></View></Card></Pressable>)}</Screen>}
const styles=StyleSheet.create({row:{flexDirection:'row',alignItems:'center',gap:space.x3},icon:{width:44,height:44,borderRadius:radius.control,backgroundColor:colors.blue50,alignItems:'center',justifyContent:'center'},copy:{flex:1},title:{...type.label,color:colors.navy950},subtitle:{...type.meta,color:colors.neutral600}});
