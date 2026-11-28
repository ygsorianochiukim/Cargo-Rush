export const garageRowDepth = 184;
const bayColumns = [545, 613, 681, 749, 817, 885];

export function garageRows(fleetSize: number) {
  return Array.from({ length: Math.max(1, Math.ceil(fleetSize / bayColumns.length)) }, (_, row) => {
    const offset = row * garageRowDepth;
    return {
      offset,
      bays: bayColumns.map((left, column) => ({
        number: row * bayColumns.length + column + 1,
        left,
        center: left + 28.5,
        bumper: 347 + offset,
      })),
    };
  });
}
