import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import type { AvatarPosture } from './office-activity';

export interface Appearance {
  hair: number;
  skin: number;
  outfit: number;
  accessory: number;
}

@Component({
  selector: 'app-operations-avatar',
  changeDetection: ChangeDetectionStrategy.OnPush,
  styleUrl: './operations-avatar.css',
  host: { 'aria-hidden': 'true' },
  templateUrl: './operations-avatar.html',
})
export class OperationsAvatar {
  readonly appearance = input.required<Appearance>();
  readonly walking = input(false);
  readonly animate = input(true);
  readonly facing = input<'left' | 'right'>('right');
  readonly posture = input<AvatarPosture>('standing');
  readonly room = input('');

  private readonly skinTones = ['#f7d8be', '#edbd97', '#cd9369', '#a56a44', '#76472e'];
  private readonly outfits = [
    'var(--room-accent, var(--color-cr-blue))',
    '#21518a',
    '#258164',
    '#8c539a',
    '#d89029',
    '#394457',
  ];

  protected readonly skin = computed(() => this.skinTones[this.appearance().skin]);
  protected readonly outfit = computed(() => this.outfits[this.appearance().outfit]);
}
