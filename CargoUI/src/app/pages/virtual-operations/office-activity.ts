import {
  adminMeetingSeats,
  adminWorkstations,
  crewBeds,
  crewSofaSeats,
} from './office-furniture-layout';

export interface FloorPoint {
  x: number;
  y: number;
}
export type Zone = 'crew' | 'garage' | 'admin';
export type AvatarPosture = 'standing' | 'seated' | 'working' | 'sleeping' | 'talking' | 'coffee';

export interface OfficeStation extends FloorPoint {
  id: string;
  label: string;
  action: string;
  icon: string;
  zone: Zone;
  posture?: AvatarPosture;
  facing?: 'left' | 'right';
}
export interface OfficePose extends FloorPoint {
  label: string;
  posture?: AvatarPosture;
  icon?: string;
  walking?: boolean;
  heading?: FloorPoint;
  facing?: 'left' | 'right';
}
export interface Home extends FloorPoint {
  posture?: AvatarPosture;
}
export interface ConversationPair {
  key: number;
  side: 0 | 1;
}

export const stations: readonly OfficeStation[] = [
  {
    id: 'crew-coffee',
    label: 'Crew coffee',
    action: 'Taking coffee',
    icon: '☕',
    zone: 'crew',
    x: 466,
    y: 250,
    posture: 'coffee',
  },
  {
    id: 'crew-chat',
    label: 'Crew conversation',
    action: 'Talking with a crewmate',
    icon: '•••',
    zone: 'crew',
    x: 250,
    y: 285,
    posture: 'talking',
  },
  {
    id: 'crew-rest',
    label: 'Crew beds',
    action: 'Resting in bed',
    icon: 'Zz',
    zone: 'crew',
    ...crewBeds[6].rest,
    posture: 'sleeping',
  },
  {
    id: 'lounge',
    label: 'Crew lounge',
    action: 'Taking a break on the sofa',
    icon: '●',
    zone: 'crew',
    ...crewSofaSeats[5],
    posture: 'seated',
  },
  {
    id: 'loading',
    label: 'Loading check',
    action: 'Checking cargo',
    icon: '▣',
    zone: 'garage',
    x: 580,
    y: 412,
  },
  {
    id: 'yard-check',
    label: 'Yard check',
    action: 'Checking parking bays',
    icon: '✓',
    zone: 'garage',
    x: 913,
    y: 403,
  },
  {
    id: 'admin-coffee',
    label: 'Admin coffee',
    action: 'Taking coffee',
    icon: '☕',
    zone: 'admin',
    x: 1237,
    y: 301,
    posture: 'coffee',
  },
  {
    id: 'planning',
    label: 'Admin planning',
    action: 'Reviewing the board',
    icon: '▣',
    zone: 'admin',
    x: 1030,
    y: 335,
  },
  {
    id: 'meeting',
    label: 'Admin conversation',
    action: 'Talking with a coworker',
    icon: '•••',
    zone: 'admin',
    x: 1125,
    y: 338,
    posture: 'talking',
  },
];

const zoneStations = {
  crew: stations.filter((station) => station.zone === 'crew' && station.posture !== 'talking'),
  garage: stations.filter((station) => station.zone === 'garage'),
  admin: stations.filter((station) => station.zone === 'admin' && station.posture !== 'talking'),
};
const talkStations = { crew: stations[1], admin: stations[8] };

export const homePositions: Record<Zone, Home[]> = {
  crew: [
    ...crewSofaSeats
      .filter((_, index) => index < 3 || index > 5)
      .map((seat) => ({ ...seat, posture: 'seated' as const })),
    ...crewBeds.map((bed) => ({ ...bed.rest, posture: 'sleeping' as const })),
  ],
  garage: [404, 435].flatMap((y) => [602, 650, 698, 746, 794, 842, 890].map((x) => ({ x, y }))),
  admin: [
    ...adminWorkstations.map((desk) => ({ ...desk.seat, posture: 'working' as const })),
    ...adminMeetingSeats
      .filter((_, index) => index !== 1 && index !== 2)
      .map((seat) => ({ ...seat, posture: 'working' as const })),
  ],
};

export function employeeZone(room: string): Zone {
  switch (room) {
    case 'drivers':
    case 'helpers':
      return 'crew';
    case 'warehouse':
      return 'garage';
    default:
      return 'admin';
  }
}

function aisle(zone: Zone, point: FloorPoint): number {
  if (zone === 'admin') return point.y > 384 ? 388 : 367;
  if (zone === 'garage') return 391;
  if (point.y > 330) return 371;
  return point.y < 245 ? 241 : 301;
}

const walkDuration = 4_200;
const cycleDuration = 70_000;
const visitDuration = (station: OfficeStation) =>
  station.posture === 'sleeping' ? 15_000 : station.posture === 'talking' ? 7_200 : 4_800;
const roundTripDuration = (station: OfficeStation) => walkDuration * 2 + visitDuration(station);

function walk(path: FloorPoint[], progress: number): OfficePose {
  const distances = path
    .slice(1)
    .map((end, index) => Math.hypot(end.x - path[index].x, end.y - path[index].y));
  let remaining = distances.reduce((total, distance) => total + distance, 0) * progress;
  for (const [index, distance] of distances.entries()) {
    if (distance > 0 && remaining <= distance) {
      const start = path[index];
      const end = path[index + 1];
      return {
        x: start.x + ((end.x - start.x) * remaining) / distance,
        y: start.y + ((end.y - start.y) * remaining) / distance,
        label: 'Walking',
        walking: true,
        heading: { x: end.x - start.x, y: end.y - start.y },
      };
    }
    remaining -= distance;
  }
  return { ...path[path.length - 1], label: 'Walking', walking: true };
}

function pathTo(from: FloorPoint, to: FloorPoint, zone: Zone): FloorPoint[] {
  if (from.y > 454 || to.y > 454) {
    const passage = { crew: 44, garage: 535, admin: 1007 }[zone];
    const fromRow = from.y > 454 ? from.y : aisle(zone, from);
    const toRow = to.y > 454 ? to.y : aisle(zone, to);
    return [
      from,
      { x: from.x, y: fromRow },
      { x: passage, y: fromRow },
      { x: passage, y: toRow },
      { x: to.x, y: toRow },
      to,
    ];
  }
  const row = aisle(zone, from);
  const destinationRow = aisle(zone, to);
  if (zone === 'crew' && row !== destinationRow) {
    const passage = from.x + to.x < 510 ? 179 : 314;
    return [
      from,
      { x: from.x, y: row },
      { x: passage, y: row },
      { x: passage, y: destinationRow },
      { x: to.x, y: destinationRow },
      to,
    ];
  }
  return [from, { x: from.x, y: row }, { x: to.x, y: row }, to];
}

export interface StationVisit {
  started: number;
  from: FloorPoint;
  station: OfficeStation;
  garageOffset?: number;
}

export function stationForEmployee(
  station: OfficeStation,
  index: number,
  side?: 0 | 1,
  home?: Home,
): OfficeStation {
  if (station.posture === 'sleeping')
    return {
      ...station,
      ...(home?.posture === 'sleeping' ? home : crewBeds[index % crewBeds.length].rest),
    };
  if (station.posture === 'talking') {
    const seats =
      station.zone === 'crew'
        ? [crewSofaSeats[3], crewSofaSeats[4]]
        : [adminMeetingSeats[1], adminMeetingSeats[2]];
    return { ...station, ...seats[side ?? 0], facing: side === 1 ? 'left' : 'right' };
  }
  return station;
}

function visitPose(
  from: FloorPoint,
  home: FloorPoint,
  station: OfficeStation,
  elapsed: number,
): OfficePose {
  const returnTime = walkDuration + visitDuration(station);
  if (elapsed < walkDuration)
    return walk(pathTo(from, station, station.zone), elapsed / walkDuration);
  if (elapsed < returnTime) return { ...station, label: station.action, icon: station.icon };
  return walk(pathTo(home, station, station.zone).reverse(), (elapsed - returnTime) / walkDuration);
}

export function officePose(
  home: Home,
  zone: Zone,
  index: number,
  elapsed: number,
  visit?: StationVisit,
  pair?: ConversationPair,
  garageOffset = 0,
): OfficePose {
  // Conversation partners share the same clock, so their visits overlap.
  const clock = (pair?.key ?? index) * 11_731 + elapsed;
  if (visit) {
    const time = elapsed - visit.started;
    const duration = roundTripDuration(visit.station);
    if (time < duration) return visitPose(visit.from, home, visit.station, time);
    if (time < duration + cycleDuration) return atHome(home, zone);
  }

  const cycle = Math.floor(clock / cycleDuration);
  const time = (clock % cycleDuration) - 10_000;
  const ordinary = zoneStations[zone];
  const station =
    pair && zone !== 'garage' && cycle % 3 === 0
      ? talkStations[zone]
      : ordinary[(cycle + index) % ordinary.length];
  const destination = stationForEmployee(
    zone === 'garage' ? { ...station, y: station.y + garageOffset } : station,
    index,
    pair?.side,
    home,
  );
  return time >= 0 && time < roundTripDuration(destination)
    ? visitPose(home, home, destination, time)
    : atHome(home, zone);
}

function atHome(home: Home, zone: Zone): OfficePose {
  const label =
    home.posture === 'sleeping'
      ? 'Resting in bed'
      : home.posture === 'working'
        ? 'Working at desk'
        : zone === 'crew'
          ? 'Relaxing in crew quarters'
          : home.posture === 'seated'
            ? 'Seated in office'
            : 'At workstation';
  return { ...home, label };
}
