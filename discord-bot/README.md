# Cargo Rush Discord assistant

A conversational assistant on Discord that answers questions about the business from live Cargo Rush data: sales, income, expenses, trips and trip history, schedules, trucks, drivers, customers, invoices, assets, payroll.

Everything about it is managed in Cargo Rush under **Administration → Assistant Settings**: on/off, the AI provider (DeepSeek or OpenAI) and its key, what it may see, and which Discord accounts may message it. Changes reach the bot within a minute, with no restart. It can only read.

## How people use it

- **DM the bot**, or **@mention it** in a channel, or **reply** to one of its messages.
- It remembers the conversation (per DM, per channel), so follow-ups like "how about last month?" work.
- Say `reset` or `new topic` to start fresh.
- Only Discord IDs listed in Assistant Settings get answers.

## Setup

1. **Bot settings file.** `cp .env.example .env` and set `CARGO_API_URL`. That is the only setting it needs, and it never changes.
2. `npm ci && npm run build && npm start`. The bot prints a connection code.
3. **Cargo Rush → Administration → Assistant Settings.** Approve the request showing that code. The bot saves its own token in `data/credentials.json`.
4. On the same page: paste the Discord bot token and the AI key (both stored encrypted), add the Discord IDs that may ask, and switch it on. The bot picks these up within a minute.
5. The bot log prints the invite link; open it to add the bot to your server.

Disconnecting the bot on the page makes it ask to connect again; approve the new code. Discord needs **Message Content Intent** turned on (Developer Portal → Bot).

## Development

```
npm run dev        # restarts on change
npm test
npm run typecheck
```

## On the server

The deploy workflow builds and runs it. Set the GitHub environment variable DISCORD_BOT=on on one environment; see deploy/README.md, "The Discord assistant".
