export const officeRightWall = 1287;

export const aiWorkstations = [1025, 1101, 1177, 1253].map((x) => ({
  x,
  y: 101,
  seat: { x, y: 125 },
}));

// The reference plan has two desks against the bottom wall and two on the right.
export const adminWorkstations = [
  { x: 1034, y: 429, angle: 180, seat: { x: 1034, y: 409 } },
  { x: 1108, y: 429, angle: 180, seat: { x: 1108, y: 409 } },
  { x: 1260, y: 354, angle: 90, seat: { x: 1240, y: 354 } },
  { x: 1260, y: 415, angle: 90, seat: { x: 1240, y: 415 } },
] as const;

export const crewBeds = [166, 386].flatMap((y) =>
  [62, 119, 176, 233, 290, 347, 404].map((x) => ({ x, y, rest: { x: x + 17, y: y + 46 } })),
);

export const crewSofas = [
  { x: 58, y: 260 },
  { x: 192, y: 260 },
  { x: 326, y: 260 },
  { x: 58, y: 328 },
  { x: 192, y: 328 },
  { x: 326, y: 328 },
];

export const crewSofaSeats = crewSofas.flatMap(({ x, y }) =>
  [26, 58, 90].map((offset) => ({ x: x + offset, y: y + 25 })),
);

export const adminMeetingSeats = [
  ...[1080, 1110, 1140, 1170].map((x) => ({ x, y: 338 })),
  ...[1080, 1120, 1160].map((x) => ({ x, y: 296 })),
];
