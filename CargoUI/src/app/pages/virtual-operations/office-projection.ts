import type { FloorPoint } from './office-activity';

export const sceneWidth = 1425;
export const sceneHeight = 560;

const camera = { a: 0.96, b: -0.143, c: 0.255, d: 0.74, x: 28, y: 199 };
export const groundTransform = `matrix(${camera.a} ${camera.b} ${camera.c} ${camera.d} ${camera.x} ${camera.y})`;

export function project(point: FloorPoint, elevation = 0): FloorPoint {
  return {
    x: camera.a * point.x + camera.c * point.y + camera.x,
    y: camera.b * point.x + camera.d * point.y + camera.y - elevation,
  };
}

export type SceneVertex = readonly [x: number, y: number, elevation: number];

export function polygon(vertices: readonly SceneVertex[]): string {
  return vertices
    .map(([x, y, z]) => {
      const point = project({ x, y }, z);
      return `${point.x.toFixed(1)},${point.y.toFixed(1)}`;
    })
    .join(' ');
}

export type Palette = readonly [top: string, front: string, side: string];

export function box(
  x: number,
  y: number,
  width: number,
  depth: number,
  height: number,
  colors: Palette,
  base = 0,
) {
  const right = x + width;
  const front = y + depth;
  return {
    depth: project({ x: right, y: front }).y,
    faces: [
      {
        points: polygon([
          [right, y, base],
          [right, front, base],
          [right, front, height],
          [right, y, height],
        ]),
        fill: colors[2],
      },
      {
        points: polygon([
          [x, front, base],
          [right, front, base],
          [right, front, height],
          [x, front, height],
        ]),
        fill: colors[1],
      },
      {
        points: polygon([
          [x, y, height],
          [right, y, height],
          [right, front, height],
          [x, front, height],
        ]),
        fill: colors[0],
      },
    ],
  };
}
