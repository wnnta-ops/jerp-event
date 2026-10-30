// History — this session's decisions first, then the seeded past (via the service).

import React from 'react';
import { View, Text, StyleSheet, ScrollView } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useNavigation } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { colors, headerTop } from '../theme/tokens';
import HistoryRow from '../components/HistoryRow';
import EmptyState from '../components/EmptyState';
import { useApp } from '../state/AppContext';
import type { RootStackParamList } from '../navigation/types';

type Nav = NativeStackNavigationProp<RootStackParamList>;

export default function HistoryScreen() {
  const { history } = useApp();
  const navigation = useNavigation<Nav>();

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <View style={styles.head}>
        <Text style={styles.title}>History</Text>
      </View>
      <ScrollView style={styles.scroll} contentContainerStyle={styles.scrollContent} showsVerticalScrollIndicator={false}>
        {history.length === 0 ? (
          <EmptyState title="No history yet" message="Your past decisions will appear here." />
        ) : (
          history.map((d, i) => (
            <HistoryRow key={i} entry={d} onPress={() => navigation.navigate('HistoryDetail', { entry: d })} />
          ))
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.white },
  head: { paddingHorizontal: 16, paddingTop: headerTop },
  title: { fontSize: 18, fontWeight: '800', color: colors.ink, marginBottom: 4 },
  scroll: { flex: 1 },
  scrollContent: { paddingHorizontal: 16, paddingTop: 6, paddingBottom: 16 },
});
