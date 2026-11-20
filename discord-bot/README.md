# Cargo Rush Discord assistant

A conversational assistant on Discord that answers questions about the business from live Cargo Rush data: sales, income, expenses, trips and trip history, schedules, trucks, drivers, customers, invoices, assets, payroll.

Everything about it is managed in Cargo Rush under **Administration → Assistant Settings**: on/off, the AI provider (DeepSeek or OpenAI) and its key, what it may see, and which Discord accounts may message it. Changes reach the bot within a minute, with no restart. It can only read.

## How people use it

- Before channels are configured, **DM the bot**, **@mention it** in a channel, or **reply** to one of its messages.
- It remembers the conversation (per DM, per channel), so follow-ups like "how about last month?" work.
- Say `reset` or `new topic` to start fresh.
- Only Discord IDs listed in Assistant Settings get answers.

## Private channels and finance updates

1. In Discord, create private server text channels such as **#assistant**, **#finance-bot**, and optionally **#finance-alerts**. Deny **View Channel** for **@everyone**, then give access to the bot and the staff who should be able to read the conversation and reports. Give the bot **Send Messages**, **Read Message History**, and **Attach Files** in the appropriate channels.
2. Save the channel IDs or Discord channel links under **Development → Automation Dashboard → Manage Discord bots** in Cargo Rush. Optionally set the minimum PHP amount for large payment alerts. In **Administration → Roles**, grant **Development → Developer Access** to your Developer role.
3. Once any channel is configured, authorized users can ask questions directly in the configured Assistant and Finance channels without a mention. The Alerts channel is for notifications. If it is blank, alerts go to the Finance channel.

Finance posts a report for the previous date after midnight in the bot's configured timezone (Asia/Manila by default). It includes recorded payments, invoice statuses the overdue check changed, and the check run history. Completed checks that marked invoices overdue, failed checks, and payments at or above the configured threshold are reported on the next polling cycle. Reports run independently of the AI provider while the assistant switch is on. Keep the bot's data directory between deployments to retain delivery progress.

## Expense receipts

Upload a JPEG, PNG, or WebP receipt of up to 2 MB directly in the private Finance channel. The bot saves the original, uses the configured AI provider to read the image, and shows an Other Expenses preview. **Update** sends the uploader a private link to the authenticated Cargo Rush editor. Save the draft and click **Update** in Discord to refresh the preview. **Confirm** creates one expense after the required fields validate. **Cancel** archives the draft and original image.

An administrator grants the uploader **Confirm receipts** under **Administration → Assistant Settings → Who can message it**. Only the uploader can use the preview buttons. **Other Expenses → Expense archive** contains the original images, draft revisions, and archived expense entries. Confirmed receipt expenses appear in the daily finance report.

## Payroll alerts

Set a dedicated private **HR assistant channel** in the Automation Dashboard. Give access to the bot and the staff authorized to see employee pay. The bot sends Office and Crew payroll alerts with the planned pay date, total payroll cost, and each employee’s net pay.

Under **Benefits → Payroll cutoff**, set any planned pay date overrides and enable **Prepare Office and Crew payroll drafts automatically**. Laravel checks daily at noon Manila time and creates drafts on their planned pay date after the cutoff has closed. Review, approval, and payment continue through the existing payroll screens.

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
