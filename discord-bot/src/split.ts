export const DISCORD_LIMIT = 2000;

function unclosedFence(text: string): string | null {
  let open: string | null = null;

  for (const line of text.match(/^```.*$/gm) ?? []) {
    open = open === null ? line : null;
  }

  return open;
}

export function splitMessage(text: string, limit = DISCORD_LIMIT): string[] {
  const chunks: string[] = [];
  let rest = text.trim();
  let carry = "";

  while (rest.length > 0) {
    const prefix = carry ? `${carry}\n` : "";

    if (prefix.length + rest.length <= limit) {
      chunks.push(prefix + rest);
      break;
    }

    const room = limit - prefix.length - 4;
    let cut = rest.lastIndexOf("\n\n", room);
    if (cut < room * 0.5) cut = rest.lastIndexOf("\n", room);
    if (cut < room * 0.5) cut = rest.lastIndexOf(" ", room);
    if (cut <= 0) cut = room;

    let piece = prefix + rest.slice(0, cut).trimEnd();
    rest = rest.slice(cut).trimStart();

    const open = unclosedFence(piece);
    if (open !== null) piece += "\n```";
    carry = open ?? "";

    chunks.push(piece);
  }

  return chunks;
}
