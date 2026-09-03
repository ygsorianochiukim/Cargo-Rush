import { ReactNode } from 'react';
import { Modal, Pressable, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Icon } from './icon';
import { Brand, Radius, Spacing } from '@/constants/theme';

/**
 * The mobile counterpart of the web modal (DESIGN.md section 8). A phone gets a
 * bottom sheet rather than a centred dialog, but the parts are the same:
 * scrim, header with title and close, body, footer actions.
 */
export function Sheet({
  open,
  title,
  subtitle,
  icon,
  danger = false,
  onClose,
  children,
  footer,
}: {
  open: boolean;
  title: string;
  subtitle?: string;
  icon?: string;
  danger?: boolean;
  onClose: () => void;
  children?: ReactNode;
  footer?: ReactNode;
}) {
  const insets = useSafeAreaInsets();

  return (
    <Modal
      visible={open}
      transparent
      animationType="slide"
      onRequestClose={onClose}
      accessibilityViewIsModal>
      <View style={styles.root}>
        <Pressable
          style={styles.scrim}
          onPress={onClose}
          accessibilityRole="button"
          accessibilityLabel="Close"
        />
        <View style={[styles.sheet, { paddingBottom: insets.bottom + Spacing.three }]}>
          <View style={styles.grabber} />

          <View style={styles.header}>
            {icon ? (
              <View
                style={[
                  styles.headerIcon,
                  { backgroundColor: danger ? Brand.redBg : Brand.tint },
                ]}>
                <Icon name={icon} size={18} color={danger ? Brand.red : Brand.blue} />
              </View>
            ) : null}
            <View style={{ flex: 1, minWidth: 0 }}>
              <Text style={styles.title}>{title}</Text>
              {subtitle ? <Text style={styles.subtitle}>{subtitle}</Text> : null}
            </View>
            <Pressable
              onPress={onClose}
              accessibilityRole="button"
              accessibilityLabel="Close dialog"
              style={styles.close}>
              <Icon name="close" size={18} color={Brand.inkMuted} />
            </Pressable>
          </View>

          {children ? <View style={styles.body}>{children}</View> : null}
          {footer ? <View style={styles.footer}>{footer}</View> : null}
        </View>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, justifyContent: 'flex-end' },
  scrim: { ...StyleSheet.absoluteFill, backgroundColor: 'rgba(31,31,31,0.5)' },
  sheet: {
    backgroundColor: Brand.surface,
    borderTopLeftRadius: Radius.panel,
    borderTopRightRadius: Radius.panel,
    paddingHorizontal: Spacing.four,
    paddingTop: Spacing.two,
  },
  grabber: {
    alignSelf: 'center',
    width: 36,
    height: 4,
    borderRadius: Radius.full,
    backgroundColor: Brand.line,
    marginBottom: Spacing.three,
  },
  header: { flexDirection: 'row', alignItems: 'center', gap: Spacing.three },
  headerIcon: {
    width: 36,
    height: 36,
    borderRadius: Radius.control,
    alignItems: 'center',
    justifyContent: 'center',
  },
  title: { fontSize: 16, fontWeight: '600', color: Brand.ink },
  subtitle: { marginTop: 2, fontSize: 13, color: Brand.inkMuted },
  close: { width: 32, height: 32, alignItems: 'center', justifyContent: 'center' },
  body: { marginTop: Spacing.three },
  footer: { marginTop: Spacing.four, gap: Spacing.two },
});
