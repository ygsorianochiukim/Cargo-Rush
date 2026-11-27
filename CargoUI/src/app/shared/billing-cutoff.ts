import { fmt } from './format';

export function cutoffDate(date: string): string {
  return fmt.date(`${date}T00:00:00`);
}

export function cutoffRange(start: string, end: string): string {
  return `${cutoffDate(start)} – ${cutoffDate(end)}`;
}
