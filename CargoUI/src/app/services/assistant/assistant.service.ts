import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import {
  AssistantSettings,
  AssistantSettingsPayload,
  DiscordBotConfiguration,
  DiscordBotSettings,
} from '../../models/assistant/assistant.model';
import { ApiService } from '../shared/api.service';

@Injectable({ providedIn: 'root' })
export class AssistantService {
  private readonly api = inject(ApiService);

  settings(): Observable<AssistantSettings> {
    return this.api.get<AssistantSettings>('assistant/settings');
  }

  save(payload: AssistantSettingsPayload): Observable<AssistantSettings> {
    return this.api.put<AssistantSettings>('assistant/settings', payload);
  }

  discordSettings(): Observable<DiscordBotSettings> {
    return this.api.get<DiscordBotSettings>('automation/bots/discord');
  }

  saveDiscordSettings(payload: Partial<DiscordBotConfiguration>): Observable<DiscordBotSettings> {
    return this.api.put<DiscordBotSettings>('automation/bots/discord', payload);
  }

  addDiscordUser(discordId: string, label: string | null, mayConfirmReceipts?: boolean): Observable<AssistantSettings> {
    return this.api.post<AssistantSettings>('assistant/discord-users', {
      discord_id: discordId, label, may_confirm_receipts: mayConfirmReceipts,
    });
  }

  removeDiscordUser(id: string): Observable<AssistantSettings> {
    return this.api.deleteItem<AssistantSettings>(`assistant/discord-users/${id}`);
  }

  approvePairing(id: string): Observable<AssistantSettings> {
    return this.api.post<AssistantSettings>(`assistant/pairings/${id}/approve`, {});
  }

  dismissPairing(id: string): Observable<AssistantSettings> {
    return this.api.deleteItem<AssistantSettings>(`assistant/pairings/${id}`);
  }

  revokeToken(): Observable<AssistantSettings> {
    return this.api.deleteItem<AssistantSettings>('assistant/token');
  }
}
