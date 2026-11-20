export type AssistantProvider = 'deepseek' | 'openai';
export type AssistantBotRole = 'accountant' | 'administrator';

export type AssistantChannelField =
  | 'discord_assistant_channel_id'
  | 'discord_finance_channel_id'
  | 'discord_finance_alerts_channel_id'
  | 'discord_hr_channel_id';

export interface DiscordBotConfiguration extends Record<AssistantChannelField, string | null> {
  enabled: boolean;
  discord_payment_alert_pesos: number | null;
}

export interface DiscordBotSettings extends DiscordBotConfiguration {
  paired: boolean;
  chat_configured: boolean;
  discord_status: AssistantDiscordStatus | null;
}

export interface AssistantDiscordUser {
  id: string;
  discord_id: string;
  label: string | null;
  may_confirm_receipts: boolean;
  added_at: string | null;
}

export interface AssistantSettings {
  enabled: boolean;
  provider: AssistantProvider;
  model: string | null;
  model_in_use: string;
  has_key: boolean;
  key_hint: string | null;
  thinking: boolean;
  has_discord_token: boolean;
  discord_token_hint: string | null;
  bot_role: AssistantBotRole;
  providers: { value: AssistantProvider; label: string; default_model: string }[];
  bot_roles: AssistantBotRole[];
  discord_status: AssistantDiscordStatus | null;
  token: { issued: boolean; created_at: string | null; last_used_at: string | null };
  discord_users: AssistantDiscordUser[];
  pairings: AssistantPairing[];
}

export interface AssistantPairing {
  id: string;
  code: string;
  ip_address: string | null;
  requested_at: string | null;
  expires_at: string;
}

export interface AssistantSettingsPayload extends Partial<Record<AssistantChannelField, string | null>> {
  enabled?: boolean;
  llm_provider?: AssistantProvider;
  llm_model?: string | null;
  llm_thinking?: boolean;
  bot_role?: AssistantBotRole;
  api_key?: string;
  clear_key?: boolean;
  discord_token?: string;
  clear_discord_token?: boolean;
  discord_payment_alert_pesos?: number | null;
}

export interface AssistantDiscordStatus {
  state: 'online' | 'connecting' | 'no_token' | 'refused';
  tag?: string | null;
  servers?: number | null;
  invite_url?: string | null;
  error?: string | null;
  finance_error?: string | null;
  hr_error?: string | null;
  reported_at: string;
}
