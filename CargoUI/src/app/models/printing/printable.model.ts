export type ElementType = 'text' | 'image' | 'table' | 'line' | 'box';
export type Zone = 'top' | 'bottom' | 'flow';
export type Align = 'left' | 'center' | 'right';

export interface TableColumn {
  key: string;
  label: string;
  align: Align;
  hidden: boolean;
}

export interface PrintElement {
  id: string;
  type: ElementType;
  label: string;
  zone: Zone;
  x: number;
  y: number;
  w: number | null;
  h: number | null;
  hidden: boolean;
  custom: boolean;

  text?: string;
  size?: number;
  bold?: boolean;
  italic?: boolean;
  upper?: boolean;
  align?: Align;
  color?: string;
  line_height?: number;

  source?: string;

  thickness?: number;
  fill?: string | null;

  table?: string;
  title?: string;
  striped?: boolean;
  space_before?: number;
  columns?: TableColumn[];
}

export interface PrintLayout {
  margin: number;
  top_height: number;
  bottom_height: number;
  elements: PrintElement[];
}

export interface PrintTable {
  rows: Record<string, string>[];
  foot?: Record<string, string>[];
}

export interface PrintPage {
  values: Record<string, string>;
  tables: Record<string, PrintTable>;
}

export interface PrintableSummary {
  key: string;
  name: string;
  group: string;
  description: string;
  orientation: 'portrait' | 'landscape';
  customised: boolean;
}

export interface PrintableEditor {
  key: string;
  name: string;
  orientation: 'portrait' | 'landscape';
  customised: boolean;
  fields: Record<string, string>;
  tables: Record<string, { key: string; label: string; align?: Align }[]>;
  layout: PrintLayout;
  defaults: PrintLayout;
  sample: { pages: PrintPage[] };
  images: Record<string, string | null>;
}

export interface UploadedPrintImage {
  source: string;
  url: string;
  ratio: number;
}
