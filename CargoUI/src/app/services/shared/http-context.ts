import { HttpContextToken } from '@angular/common/http';

export const AUTH_PROBE = new HttpContextToken<boolean>(() => false);
