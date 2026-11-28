import { describe, expect, it } from 'vitest';
import { officePose, Zone } from './office-activity';
import { officeCapacity } from './office-capacity';
import { project } from './office-projection';

describe('live office capacity', () => {
  it('gives a large roster distinct places inside the expanded scene', () => {
    const roster = { crew: 33, garage: 19, admin: 17 };
    const capacity = officeCapacity(roster, 14);
    const points = (Object.keys(roster) as Zone[]).flatMap((zone) =>
      capacity.homes[zone].slice(0, roster[zone]),
    );
    expect(points).toHaveLength(69);
    expect(new Set(points.map(({ x, y }) => `${x}:${y}`)).size).toBe(69);
    for (const point of points) {
      const { x, y } = project(point);
      expect(x).toBeLessThan(capacity.width);
      expect(y).toBeLessThan(capacity.height);
    }
  });

  it('adds numbered rows without shifting existing bays and places warehouse staff past the last row', () => {
    const people = { crew: 1, garage: 20, admin: 1 };
    const small = officeCapacity(people, 6);
    const expanded = officeCapacity(people, 14);
    const bays = expanded.garage.flatMap(({ bays }) => bays);
    expect(expanded.garage[0]).toEqual(small.garage[0]);
    expect(bays[13].number).toBe(14);
    expect(bays[13].bumper).toBeGreaterThan(bays[7].bumper);
    expect(Math.min(...expanded.homes.garage.map(({ y }) => y))).toBeGreaterThan(bays[13].bumper);

    const home = expanded.homes.garage[0];
    for (let time = 0; time < 70_000; time += 200) {
      expect(
        officePose(home, 'garage', 0, time, undefined, undefined, expanded.garageExtension).y,
      ).toBeGreaterThan(bays[13].bumper);
    }
  });
});
