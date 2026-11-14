export type AssistantProvider = 'deepseek' | 'openai';
export type AssistantBotRole = 'accountant' | 'administrator';

export interface AssistantDiscordUser {
  id: string;
  discord_id: string;
  label: string | null;
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

export interface AssistantSettingsPayload {
  enabled?: boolean;
  llm_provider?: AssistantProvider;
  llm_model?: string | null;
  llm_thinking?: boolean;
  bot_role?: AssistantBotRole;
  api_key?: string;
  clear_key?: boolean;
  discord_token?: string;
  clear_discord_token?: boolean;
}
