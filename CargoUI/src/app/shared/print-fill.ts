const FIELD = /\{\{\s*(\??)([a-z0-9_.]+)\s*\}\}/gi;

export function fillText(text: string, values: Record<string, string>): string {
  return text
    .split(/\r\n|\r|\n/)
    .filter((line) => {
      const found = [...line.matchAll(FIELD)];

      return found.length === 0 || found.some((m) => (values[m[2]] ?? '').trim() !== '');
    })
    .map((line) =>
      line.replace(FIELD, (_all, flag: string, key: string) =>
        flag === '?' ? '' : (values[key] ?? ''),
      ),
    )
    .join('\n');
}
