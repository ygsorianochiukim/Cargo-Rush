import { garageRowDepth, garageRows } from './garage-layout';
import { Home, Zone, homePositions } from './office-activity';
import { officeRightWall } from './office-furniture-layout';
import { project, sceneHeight, sceneWidth } from './office-projection';

export function officeCapacity(people: Record<Zone, number>, fleetSize: number) {
  const garage = garageRows(fleetSize);
  const garageExtension = (garage.length - 1) * garageRowDepth;
  const extraRows = (zone: Zone, columns: number) =>
    Math.max(0, Math.ceil((people[zone] - homePositions[zone].length) / columns));

  const beds = Array.from({ length: extraRows('crew', 7) }, (_, row) =>
    [62, 119, 176, 233, 290, 347, 404].map((x) => {
      const y = 476 + row * 85;
      return { x, y, rest: { x: x + 17, y: y + 46 } };
    }),
  ).flat();
  const desks = Array.from({ length: extraRows('admin', 4) }, (_, row) =>
    [1044, 1108, 1172, 1236].map((x) => {
      const y = 505 + row * 76;
      return { x, y, angle: 180 as const, seat: { x, y: y - 20 } };
    }),
  ).flat();
  const warehouseRows = extraRows('garage', 7);
  const warehouse = Array.from({ length: warehouseRows }, (_, row) =>
    [602, 650, 698, 746, 794, 842, 890].map((x) => ({
      x,
      y: 481 + garageExtension + row * 47,
    })),
  ).flat();

  const homes: Record<Zone, Home[]> = {
    crew: [
      ...homePositions.crew,
      ...beds.map(({ rest }) => ({ ...rest, posture: 'sleeping' as const })),
    ],
    garage: [
      ...homePositions.garage.map((home) => ({ ...home, y: home.y + garageExtension })),
      ...warehouse,
    ],
    admin: [
      ...homePositions.admin,
      ...desks.map(({ seat }) => ({ ...seat, posture: 'working' as const })),
    ],
  };

  const fronts = {
    crew: 454 + extraRows('crew', 7) * 85,
    garage: 454 + garageExtension + warehouseRows * 47 + (warehouseRows ? 9 : 0),
    admin: 454 + extraRows('admin', 4) * 76,
  };
  const corners = [
    project({ x: 22, y: fronts.crew }, -11),
    project({ x: 512, y: fronts.crew }, -11),
    project({ x: 520, y: fronts.garage }, -11),
    project({ x: 971, y: fronts.garage }, -11),
    project({ x: 990, y: fronts.admin }, -11),
    project({ x: officeRightWall, y: fronts.admin }, -11),
  ];
  return {
    width: Math.max(sceneWidth, Math.ceil(Math.max(...corners.map(({ x }) => x)) + 43)),
    height: Math.max(sceneHeight, Math.ceil(Math.max(...corners.map(({ y }) => y)) + 33)),
    fronts,
    garage,
    garageExtension,
    homes,
    beds,
    desks,
    warehouse,
  };
}

export type OfficeCapacity = ReturnType<typeof officeCapacity>;
