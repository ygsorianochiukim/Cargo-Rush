import { Platform } from 'react-native';

import { ProofPhoto } from '@/models/delivery/delivery.model';

/**
 * Put a picked photograph into a multipart body.
 *
 * On a handset `FormData` takes `{ uri, name, type }` and the native bridge
 * reads the file from the URI. In a browser it does not: that object is
 * stringified to "[object Object]", and the API rightly answers that it is not
 * a photograph. There the URI (a `blob:` or `data:` URL from the picker) is
 * read into a real `Blob` first.
 */
export async function appendPhoto(body: FormData, field: string, photo: ProofPhoto): Promise<void> {
  if (Platform.OS === 'web') {
    const blob = await (await fetch(photo.uri)).blob();

    body.append(field, blob, photo.name);

    return;
  }

  body.append(field, photo as unknown as Blob);
}
