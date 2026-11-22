import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, DestroyRef, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Observable, finalize } from 'rxjs';

import { ApiService } from '../../services/shared/api.service';
import { Card } from '../../shared/card';
import { Field } from '../../shared/field';

interface Brand {
  mission: string;
  vision: string;
  voice: string;
  audience: string;
  guidelines: string | null;
}

const FORMATS = { photo: 'Photo', carousel: 'Carousel', reel: 'Reel', text: 'Text post' };

interface Content {
  format: keyof typeof FORMATS;
  hook: string;
  caption: string;
  cta: string;
  visual_brief: string;
}

interface Draft {
  id: string;
  topic: string;
  content: Content;
  trend: { topic: string; source: string; checked_at: string } | null;
}

interface MarketingDashboard {
  brand: Brand | null;
  ai_configured: boolean;
  drafts: Draft[];
}

@Component({
  selector: 'app-marketing-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, DatePipe, Field, FormsModule, RouterLink],
  templateUrl: './marketing.page.html',
})
export class MarketingPage {
  private readonly api = inject(ApiService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly savedBrand = signal<Brand | null>(null);
  protected readonly brand = signal<Brand>({ mission: '', vision: '', voice: '', audience: '', guidelines: null });
  protected readonly brandFields = [
    { key: 'mission', label: 'Mission', max: 1000, required: true },
    { key: 'vision', label: 'Vision', max: 1000, required: true },
    { key: 'voice', label: 'Brand voice', max: 500, required: true },
    { key: 'audience', label: 'Target customers', max: 500, required: true },
    { key: 'guidelines', label: 'Products and brand guidelines', max: 1500, required: false },
  ] as const;
  protected readonly formats = FORMATS;
  protected readonly formatKeys = Object.keys(FORMATS) as Content['format'][];
  protected readonly editFields = [
    { key: 'hook', label: 'Hook', max: 250, rows: 2 },
    { key: 'caption', label: 'Caption', max: 2200, rows: 5 },
    { key: 'cta', label: 'Call to action', max: 250, rows: 2 },
    { key: 'visual_brief', label: 'Visual brief or script', max: 1200, rows: 3 },
  ] as const;
  protected readonly topic = signal('');
  protected readonly useTrends = signal(true);
  protected readonly configured = signal(false);
  protected readonly drafts = signal<Draft[]>([]);
  protected readonly selection = signal<Draft | null>(null);
  protected readonly edit = signal<Content | null>(null);
  protected readonly busy = signal<'load' | 'brand' | 'generate' | 'draft' | null>(null);
  protected readonly loaded = signal(false);
  protected readonly error = signal('');
  protected readonly notice = signal('');
  protected readonly brandReady = computed(() =>
    this.brandFields.every((field) => !field.required || this.brand()[field.key]?.trim()),
  );
  protected readonly brandDirty = computed(() =>
    this.brandFields.some(({ key }) => (this.brand()[key] ?? '') !== (this.savedBrand()?.[key] ?? '')),
  );
  protected readonly draftDirty = computed(() => {
    const current = this.edit();
    const original = this.selection()?.content;
    return current !== null && (current.format !== original?.format ||
      this.editFields.some(({ key }) => current[key] !== original?.[key]));
  });
  protected readonly draftReady = computed(() => this.editFields.every(({ key }) => this.edit()?.[key].trim()));

  constructor() {
    this.load();
  }

  protected load(): void {
    this.request('load', this.api.get<MarketingDashboard>('marketing'), ({ brand, ai_configured, drafts }) => {
      if (brand) this.brand.set(brand);
      this.savedBrand.set(brand);
      this.configured.set(ai_configured);
      this.drafts.set(drafts);
      this.select(drafts[0] ?? null);
      this.loaded.set(true);
    });
  }

  protected changeBrand(key: keyof Brand, value: string): void {
    this.brand.update((brand) => ({ ...brand, [key]: value }));
  }

  protected saveBrand(): void {
    if (this.busy() || !this.brandReady() || !this.brandDirty()) return;
    this.request('brand', this.api.put<Brand>('marketing/brand', this.brand()), (brand) => {
      this.brand.set(brand);
      this.savedBrand.set(brand);
      this.notice.set('Brand profile saved.');
    });
  }

  protected generate(): void {
    if (this.busy() || !this.configured() || !this.savedBrand() || this.brandDirty() || this.draftDirty() || !this.topic().trim()) return;
    this.request('generate', this.api.post<Draft[]>('marketing/drafts', {
      topic: this.topic(), use_trends: this.useTrends(),
    }), (drafts) => {
      this.drafts.update((current) => [...drafts, ...current].slice(0, 25));
      this.select(drafts[0] ?? null);
      this.notice.set('Two drafts generated and saved.');
    });
  }

  protected select(draft: Draft | null): void {
    this.selection.set(draft);
    this.edit.set(draft?.content ?? null);
  }

  protected chooseDraft(id: string): void {
    this.select(this.drafts().find((draft) => draft.id === id) ?? null);
  }

  protected changeDraft<K extends keyof Content>(key: K, value: Content[K]): void {
    this.edit.update((content) => content && { ...content, [key]: value });
  }

  protected saveDraft(): void {
    const selected = this.selection();
    const content = this.edit();
    if (this.busy() || !selected || !content || !this.draftReady() || !this.draftDirty()) return;
    this.request('draft', this.api.put<Draft>(`marketing/drafts/${selected.id}`, content), (draft) => {
      this.drafts.update((current) => [draft, ...current.filter(({ id }) => id !== draft.id)]);
      this.select(draft);
      this.notice.set('Draft updated.');
    });
  }

  private request<T>(action: NonNullable<ReturnType<typeof this.busy>>, request: Observable<T>, success: (result: T) => void): void {
    this.busy.set(action);
    this.error.set('');
    this.notice.set('');
    request.pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.busy.set(null))).subscribe({
      next: success,
      error: (response: HttpErrorResponse) => {
        const errors = response.error?.errors as Record<string, string[]> | undefined;
        this.error.set(Object.values(errors ?? {})[0]?.[0]
          ?? (response.status === 429 ? 'This company has reached its daily generation limit.' : response.error?.message)
          ?? 'Marketing could not complete the request. Please try again.');
      },
    });
  }
}
