export type FileDelivery = 'save' | 'print';

export function saveFile(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');

  link.href = url;
  link.download = filename;
  link.click();

  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

export function printFile(blob: Blob): void {
  const url = URL.createObjectURL(new Blob([blob], { type: 'application/pdf' }));
  const frame = document.createElement('iframe');

  frame.style.position = 'fixed';
  frame.style.right = '0';
  frame.style.bottom = '0';
  frame.style.width = '0';
  frame.style.height = '0';
  frame.style.border = '0';
  frame.src = url;

  frame.onload = () => {
    frame.contentWindow?.focus();
    frame.contentWindow?.print();

    setTimeout(() => {
      frame.remove();
      URL.revokeObjectURL(url);
    }, 60_000);
  };

  document.body.appendChild(frame);
}

export function deliverFile(mode: FileDelivery, blob: Blob, filename: string): void {
  if (mode === 'print') printFile(blob);
  else saveFile(blob, filename);
}
