import { box, Palette, polygon, SceneVertex } from './office-projection';

type QuarterTurn = 0 | 90 | 180;
type Solid = ReturnType<typeof box>;

interface Furniture extends Solid {
  shadow?: string;
}

const oak = ['#ffe2b4', '#d4a471', '#b9875b'] as const;
const walnut = ['#c68b5f', '#986748', '#795440'] as const;
const darkWood = ['#a77551', '#815838', '#694734'] as const;
const metal = ['#6a838e', '#455b69', '#344956'] as const;
const upholstery = ['#75a3ad', '#4c7886', '#3b5f70'] as const;
const linen = ['#fff6e7', '#dfd7cb', '#c6bcae'] as const;
const pillow = ['#fffdf3', '#efe7d8', '#dcd0c0'] as const;
const bezel = ['#648090', '#344b5b', '#253744'] as const;
const glass = ['#a8dce0', '#729fae', '#578898'] as const;

export function furnishing(...parts: Furniture[]): Furniture {
  return {
    depth: Math.max(...parts.map((part) => part.depth)),
    faces: parts.flatMap((part) => part.faces),
    shadow: parts.find((part) => part.shadow)?.shadow,
  };
}

function position(x: number, y: number, angle: QuarterTurn = 0) {
  const vertex = (dx: number, dy: number, z: number): SceneVertex => {
    if (angle === 90) return [x - dy, y + dx, z];
    if (angle === 180) return [x - dx, y - dy, z];
    return [x + dx, y + dy, z];
  };
  return {
    part(dx: number, dy: number, w: number, d: number, top: number, colors: Palette, base = 0) {
      const [left, back] = vertex(dx, dy, base);
      const [right, front] = vertex(dx + w, dy + d, base);
      return box(
        Math.min(left, right),
        Math.min(back, front),
        Math.abs(right - left),
        Math.abs(front - back),
        top,
        colors,
        base,
      );
    },
    shadow(left: number, back: number, right: number, front: number) {
      return polygon([
        vertex(left + 3, back, 0),
        vertex(right - 3, back, 0),
        vertex(right, back + 3, 0),
        vertex(right, front - 3, 0),
        vertex(right - 3, front, 0),
        vertex(left + 3, front, 0),
        vertex(left, front - 3, 0),
        vertex(left, back + 3, 0),
      ]);
    },
  };
}

export function table(
  x: number,
  y: number,
  width: number,
  depth: number,
  height: number,
  angle: QuarterTurn = 0,
  top: Palette = oak,
) {
  const { part, shadow } = position(x, y, angle);
  const left = -width / 2;
  const back = -depth / 2;
  const supports = [left + 3, width / 2 - 7]
    .flatMap((dx) =>
      [back + 3, depth / 2 - 7].map((dy) => part(dx, dy, 4, 4, height - 4, darkWood)),
    )
    .sort((a, b) => a.depth - b.depth);
  return {
    ...furnishing(
      ...supports,
      part(left + 3, back + 3, width - 6, depth - 6, height - 3, walnut, height - 7),
      part(left, back, width, depth, height, top, height - 3),
      part(left + 2, back + 2, width - 4, 1, height + 0.1, linen, height),
    ),
    shadow: shadow(left - 1, back, width / 2 + 4, depth / 2 + 4),
  };
}

export function workstation(x: number, y: number, angle: QuarterTurn, screen: Palette) {
  const { part } = position(x, y, angle);
  const height = 19;
  const surface = table(x, y, 48, 24, height, angle);
  const equipment = [
    part(-9, -8, 18, 7, height + 1.5, metal, height),
    part(-2, -7, 4, 3, height + 7, bezel, height + 1.5),
    part(-12, -9, 24, 4, height + 18, bezel, height + 6),
    part(-10, angle === 0 ? -5 : -10, 20, 1, height + 16, angle === 0 ? screen : metal, height + 8),
    part(-2, angle === 0 ? -4 : -10.4, 4, 0.4, height + 10, glass, height + 9),
    part(-8, 2, 16, 5, height + 1.5, metal, height),
    part(-6, 3, 12, 2, height + 1.7, linen, height + 1.5),
    part(11, 3, 4, 4, height + 2, bezel, height),
    part(16, -5, 5, 5, height + 5, linen, height),
    part(17, -4, 3, 3, height + 5.2, darkWood, height + 5),
  ];
  return { ...surface, faces: [...surface.faces, ...equipment.flatMap(({ faces }) => faces)] };
}

export const chairSeatHeight = 11;

export function chair(x: number, y: number, angle: QuarterTurn, arms = true) {
  const { part, shadow } = position(x, y, angle);
  const wheels = [-9, 6].flatMap((dx) => [-8, 5].map((dy) => part(dx, dy, 3, 3, 3, bezel)));
  const seat = [
    part(-9, -1, 18, 3, 4, metal, 2),
    part(-1.5, -8, 3, 16, 4, metal, 2),
    part(-2, -2, 4, 4, 9, metal, 4),
    part(-9, -7, 18, 15, chairSeatHeight, upholstery, 8),
  ];
  const back = [
    part(-7, 6, 14, 3, 19, metal, 9),
    part(-9, 6, 18, 4, 25, upholstery, 16),
    part(-7, 6.5, 14, 1, 25.2, glass, 25),
  ];
  const armrests = [-10, 8].flatMap((dx) => [
    part(dx, 0, 3, 3, 16, metal, chairSeatHeight),
    part(dx, -4, 3, 9, 17.5, bezel, 16),
  ]);
  return {
    ...furnishing(
      ...wheels,
      ...(angle === 0 ? [] : back),
      ...seat,
      ...(arms ? armrests : []),
      ...(angle === 0 ? back : []),
    ),
    shadow: shadow(-10, -8, 12, 13),
  };
}

export const bedCoverHeight = 16;

export function bed(x: number, y: number, covers: Palette) {
  const { part, shadow } = position(x, y);
  const quilt = ['#e1eeeb', '#bbcfc9', '#95b0ab'] as const;
  return {
    ...furnishing(
      part(2, 2, 4, 4, 7, darkWood),
      part(29, 2, 4, 4, 7, darkWood),
      part(2, 49, 4, 4, 7, darkWood),
      part(29, 49, 4, 4, 7, darkWood),
      part(0, 0, 35, 55, 9, walnut, 5),
      part(2, 4, 31, 48, 14, linen, 9),
      part(4, 7, 27, 11, 17, pillow, 14),
      part(6, 8, 23, 8, 17.25, linen, 17),
      part(2, 20, 31, 30, bedCoverHeight, covers, 14),
      part(2, 19, 31, 5, bedCoverHeight + 1, quilt, bedCoverHeight),
      ...[29, 37, 45].map((dy) =>
        part(4, dy, 27, 0.6, bedCoverHeight + 0.1, quilt, bedCoverHeight),
      ),
      part(2, 26, 1, 22, bedCoverHeight + 0.1, quilt, bedCoverHeight),
      part(0, 0, 35, 4, 24, walnut, 9),
      part(0, 0, 35, 4.5, 25, oak, 23),
      part(0, 52, 35, 3, 13, walnut, 9),
      part(0, 52, 35, 3.5, 14, oak, 12),
    ),
    shadow: shadow(-1, 0, 39, 58),
  };
}
