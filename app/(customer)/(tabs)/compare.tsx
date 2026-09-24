import React from 'react';
import { router } from 'expo-router';
import { Scale } from 'lucide-react-native';
import { AppHeader, Button, Card, Screen } from '@/components/ui';
import { Text, StyleSheet } from 'react-native';
import { colors, space, type } from '@/theme/tokens';
export default function Compare(){return <Screen><AppHeader title="Compare" subtitle="Transparent offers from participating insurers"/><Card feature><Scale size={36} color={colors.blue600}/><Text style={styles.title}>Start with what you want to protect</Text><Text style={styles.body}>Answer a few questions once. We will show comparable cover, exclusions, fees and fulfilment details.</Text><Button label="Start comparison" onPress={()=>router.push('/quote/product')}/><Button label="Saved quotes" variant="secondary" onPress={()=>router.push('/quotes')}/><Button label="My applications" variant="tertiary" onPress={()=>router.push('/proposals')}/></Card><Card><Text style={styles.card}>No hidden ranking</Text><Text style={styles.body}>Recommended labels require an explainable basis. Carrier branding never changes the platform’s status meanings.</Text></Card></Screen>};
const styles=StyleSheet.create({title:{...type.sectionTitle,color:colors.navy950,marginTop:space.x3},card:{...type.cardTitle,color:colors.navy950},body:{...type.body,color:colors.neutral600}});
