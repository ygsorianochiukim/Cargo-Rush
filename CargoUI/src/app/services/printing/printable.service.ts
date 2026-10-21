import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import {
  PrintLayout,
  PrintableEditor,
  PrintableSummary,
  UploadedPrintImage,
} from '../../models/printing/printable.model';
import { environment } from '../../../environments/environment';
import { ApiService } from '../shared/api.service';

@Injectable({ providedIn: 'root' })
export class PrintableService {
  private readonly api = inject(ApiService);
  private readonly http = inject(HttpClient);

  list(): Observable<PrintableSummary[]> {
    return this.api.get<PrintableSummary[]>('company/printables');
  }

  show(key: string): Observable<PrintableEditor> {
    return this.api.get<PrintableEditor>(`company/printables/${key}`);
  }

  save(key: string, layout: PrintLayout): Observable<PrintableEditor> {
    return this.api.put<PrintableEditor>(`company/printables/${key}`, layout);
  }

  reset(key: string): Observable<PrintableEditor> {
    return this.api.deleteItem<PrintableEditor>(`company/printables/${key}`);
  }

  preview(key: string, layout: PrintLayout): Observable<Blob> {
    return this.http.post(
      `${environment.apiUrl}/api/v1/company/printables/${key}/preview.pdf`,
      layout,
      {
        withCredentials: true,
        responseType: 'blob',
      },
    );
  }

  uploadImage(file: File): Observable<UploadedPrintImage> {
    const form = new FormData();
    form.append('image', file);

    return this.api.postForm<UploadedPrintImage>('company/printables/images', form);
  }
}
