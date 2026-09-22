import React from 'react';
import { StyleSheet, Text } from 'react-native';
import { router } from 'expo-router';
import { LockKeyhole } from 'lucide-react-native';
import { Button, Card, Screen } from '@/components/ui';
import { colors, type } from '@/theme/tokens';
export default function AccessDenied(){return <Screen><Card feature><LockKeyhole size={32} color={colors.danger}/><Text style={styles.title}>Access denied</Text><Text style={styles.body}>This workspace is not assigned to your account. No restricted information has been loaded.</Text><Button label="Choose an authorised workspace" onPress={()=>router.replace('/(auth)/role')}/></Card></Screen>}
const styles=StyleSheet.create({title:{...type.sectionTitle,color:colors.navy950},body:{...type.body,color:colors.neutral600}});
