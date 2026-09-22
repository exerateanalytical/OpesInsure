import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { CloudOff, RefreshCw } from 'lucide-react-native';
import { Button, Card } from './ui';
import { colors, space, type } from '@/theme/tokens';

export function EmptyState({ title, message, action, onPress }: { title: string; message: string; action?: string; onPress?: () => void }) { return <Card style={styles.panel}><View style={styles.icon}><CloudOff size={24} color={colors.neutral600}/></View><Text style={styles.title}>{title}</Text><Text style={styles.message}>{message}</Text>{action && <Button label={action} variant="secondary" onPress={onPress}/>}</Card>; }
export function ErrorState({ onRetry }: { onRetry?: () => void }) { return <Card style={styles.panel}><Text style={styles.title}>We could not load this information</Text><Text style={styles.message}>Your information is safe. Check your connection and try again.</Text><Button label="Try again" icon={RefreshCw} variant="secondary" onPress={onRetry}/></Card>; }
const styles=StyleSheet.create({panel:{alignItems:'center',paddingVertical:space.x8},icon:{width:48,height:48,borderRadius:24,backgroundColor:colors.neutral100,alignItems:'center',justifyContent:'center'},title:{...type.cardTitle,color:colors.navy950,textAlign:'center'},message:{...type.body,color:colors.neutral600,textAlign:'center'}});
