import { ChangeDetectionStrategy, Component } from '@angular/core';

import { CategoriesCard } from '../access/categories-card';

@Component({
  selector: 'app-categories',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [CategoriesCard],
  template: `<app-categories-card />`,
})
export class CategoriesPage {}
