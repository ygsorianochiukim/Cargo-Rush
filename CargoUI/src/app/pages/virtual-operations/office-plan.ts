import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { garageBays } from './garage-layout';
import { bed, chair, furnishing, table, workstation } from './office-furniture';
import {
  adminWorkstations,
  adminMeetingSeats,
  aiWorkstations,
  crewBeds,
  crewSofas,
  officeRightWall,
} from './office-furniture-layout';
import {
  box,
  groundTransform,
  Palette,
  polygon,
  project,
  sceneHeight,
  sceneWidth,
} from './office-projection';

const timber = ['#e4b47d', '#b37f50', '#9b6949'] as const;
const walnut = ['#c68857', '#9b6245', '#754d3c'] as const;
const oak = ['#ffe2b4', '#d4a471', '#b9875b'] as const;
const wall = ['#fff6df', '#e8d7ba', '#b5a79a'] as const;
const steel = ['#b6cfd5', '#708f9e', '#536c7a'] as const;
const night = ['#46687e', '#304959', '#253948'] as const;
const teal = ['#7bbcaf', '#50968b', '#42796f'] as const;
const cyan = ['#83d7dd', '#3b9ba8', '#327685'] as const;
const clay = ['#f4a981', '#bf7357', '#a15f4f'] as const;
const paper = ['#fff3d6', '#e3d5b6', '#c5ae8f'] as const;
const soil = ['#bd8767', '#a76a56', '#854b3d'] as const;
const slab = ['#aeb8bf', '#708899', '#5a7081'] as const;
const leaf = ['#80b989', '#5b956e', '#427763'] as const;

function sofa(x: number, y: number, cushions: Palette) {
  return furnishing(
    box(x, y, 114, 31, 9, walnut),
    ...[9, 42, 75].map((dx) => box(x + dx, y + 12, 30, 18, 13, cushions, 9)),
    box(x, y, 114, 7, 26, cushions, 9),
    box(x, y + 7, 8, 24, 20, cushions, 9),
    box(x + 106, y + 7, 8, 24, 20, cushions, 9),
    box(x + 12, y + 10, 10, 7, 18, clay, 13),
    box(x + 91, y + 10, 10, 7, 18, oak, 13),
  );
}

function planter(x: number, y: number) {
  return furnishing(
    box(x - 6, y - 6, 12, 12, 8, soil),
    box(x - 8, y - 7, 16, 14, 18, leaf, 8),
    box(x - 4, y - 5, 10, 9, 23, leaf, 17),
  );
}

function coffee(x: number, y: number, width = 38) {
  return furnishing(
    box(x - width / 2, y - 10, width, 19, 14, walnut),
    box(x - width / 2 - 1, y - 11, width + 2, 21, 16, oak, 14),
    box(x - 9, y - 8, 17, 12, 27, night, 16),
    box(x + 10, y + 1, 5, 5, 22, paper, 16),
  );
}

function panel(x: number, y: number, width: number, bottom: number, top: number, type: string) {
  return {
    type,
    face: polygon([
      [x, y, bottom],
      [x + width, y, bottom],
      [x + width, y, top],
      [x, y, top],
    ]),
  };
}

function sign(label: string, x: number, y: number, color: string, width: number) {
  return { label, ...project({ x, y }, 39), color, width };
}

@Component({
  selector: 'app-office-plan',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { 'aria-hidden': 'true', style: 'display:block;pointer-events:none' },
  templateUrl: './office-plan.html',
  styleUrl: './office-plan.css',
})
export class OfficePlan {
  readonly animate = input(true);

  protected readonly width = sceneWidth;
  protected readonly height = sceneHeight;
  protected readonly ground = groundTransform;
  protected readonly loungeFurniture = crewSofas;
  protected readonly floors = [
    { x: 22, y: 150, w: 490, h: 304, fill: 'url(#cr-oak)' },
    { x: 520, y: 150, w: 451, h: 304, fill: 'url(#cr-concrete)' },
    { x: 990, y: 31, w: officeRightWall - 990, h: 218, fill: 'url(#cr-agent-tile)' },
    { x: 990, y: 249, w: officeRightWall - 990, h: 205, fill: 'url(#cr-oak)' },
  ];
  protected readonly bases = [
    box(18, 146, 498, 312, 0, slab, -11),
    box(516, 146, 459, 312, 0, slab, -11),
    box(986, 27, officeRightWall - 982, 431, 0, slab, -11),
  ];
  protected readonly walls = [
    box(22, 150, 490, 6, 31, wall),
    box(22, 156, 6, 298, 23, wall),
    box(520, 150, 451, 7, 33, steel),
    box(520, 157, 6, 297, 19, steel),
    box(990, 31, officeRightWall - 990, 6, 32, wall),
    box(990, 37, 6, 417, 22, wall),
    box(996, 246, 103, 5, 16, wall),
    box(1162, 246, officeRightWall - 1162, 5, 16, wall),
  ];
  protected readonly edges = [
    box(28, 448, 441, 6, 8, wall),
    box(497, 448, 15, 6, 8, wall),
    box(506, 156, 6, 292, 9, wall),
    box(965, 157, 6, 297, 9, steel),
    box(526, 448, 54, 6, 8, steel),
    box(921, 448, 44, 6, 8, steel),
    box(996, 448, officeRightWall - 996, 6, 8, wall),
    box(officeRightWall - 6, 37, 6, 411, 9, wall),
  ];
  protected readonly panels = [
    ...[69, 162, 265, 388].map((x) => panel(x, 156.1, 56, 7, 23, 'window')),
    ...[552, 688, 823].map((x) => panel(x, 157.1, 105, 2, 27, 'shutter')),
    panel(1010, 37.1, 257, 8, 24, 'window'),
  ];
  protected readonly foregroundDesks = adminWorkstations
    .map(({ x, y, angle }) => workstation(x, y, angle, cyan))
    .sort((a, b) => a.depth - b.depth);
  protected readonly furnishings = [
    ...crewBeds.map(({ x, y }, index) => bed(x, y, index % 2 === 0 ? teal : steel)),
    ...crewSofas.map(({ x, y }, index) => sofa(x, y, index < 3 ? teal : steel)),
    ...[85, 220, 354].map((x) =>
      furnishing(
        table(x + 29, 306, 58, 15, 11),
        box(x + 8, 302, 10, 7, 12, paper, 11),
        box(x + 34, 305, 7, 4, 14, clay, 11),
      ),
    ),
    ...aiWorkstations.flatMap(({ x, y, seat }) => [
      workstation(x, y, 0, cyan),
      chair(seat.x, seat.y, 0),
    ]),
    ...adminWorkstations.map(({ angle, seat }) => chair(seat.x, seat.y, angle)),
    coffee(466, 226, 46),
    coffee(1237, 280, 38),
    furnishing(
      table(1122.5, 317.5, 129, 23, 17, 0, walnut),
      box(1090, 311, 24, 7, 19, paper, 17),
      box(1150, 314, 11, 7, 19, teal, 17),
    ),
    ...adminMeetingSeats.map(({ x, y }) => chair(x, y, y < 300 ? 180 : 0, false)),
    furnishing(box(1008, 299, 30, 7, 29, night), box(1013, 306, 19, 1, 24, cyan, 17)),
    furnishing(box(1238, 189, 18, 29, 27, night), box(1241, 218, 12, 1, 22, cyan, 19)),
    furnishing(box(1261, 189, 18, 29, 25, night), box(1264, 218, 12, 1, 20, cyan, 17)),
    furnishing(box(541, 410, 28, 20, 12, timber), box(546, 414, 17, 13, 21, oak, 12)),
    furnishing(box(923, 414, 26, 20, 12, timber), box(929, 418, 17, 11, 23, walnut, 12)),
    ...[49, 481].map((x) => planter(x, 202)),
    planter(45, 431),
    planter(490, 427),
    planter(1007, 435),
    planter(1009, 215),
    planter(1215, 389),
  ].sort((a, b) => a.depth - b.depth);
  protected readonly furnitureShadows = [...this.furnishings, ...this.foregroundDesks].flatMap(
    ({ shadow }) => shadow ? [shadow] : [],
  );
  protected readonly bays = garageBays;
  protected readonly signs = [
    sign('DRIVERS & HELPERS', 42, 150, '#435c58', 163),
    sign('GARAGE / PARKING', 540, 150, '#455d73', 158),
    sign('AI AGENT OFFICE', 1007, 35, '#365a72', 126),
    sign('ADMIN OFFICE', 1007, 247, '#765540', 115),
  ];
  protected readonly steam = [project({ x: 469, y: 224 }, 27), project({ x: 1240, y: 278 }, 27)];
}
