import { describe, expect, it } from 'vitest';

import { homePositions, officePose, stationForEmployee, stations, Zone } from './office-activity';
import { officeRightWall } from './office-furniture-layout';

describe('office movement', () => {
  it('walks to a requested station, performs its activity, and returns home', () => {
    const home = homePositions.crew[0];
    const station = stations[0];
    const visit = { started: 0, from: home, station };

    expect(officePose(home, 'crew', 0, 2_000, visit)).toMatchObject({
      label: 'Walking',
      walking: true,
    });
    expect(officePose(home, 'crew', 0, 5_000, visit)).toMatchObject({
      x: station.x,
      y: station.y,
      label: 'Taking coffee',
      icon: '☕',
    });
    expect(officePose(home, 'crew', 0, 10_000, visit).walking).toBe(true);
    expect(officePose(home, 'crew', 0, 14_000, visit)).toMatchObject({
      ...home,
      label: 'Relaxing in crew quarters',
    });
  });

  it('keeps automatic trips inside the employee’s assigned physical room', () => {
    const bounds: Record<Zone, [number, number, number, number]> = {
      crew: [22, 512, 150, 454],
      garage: [520, 971, 380, 454],
      admin: [990, officeRightWall, 249, 454],
    };

    for (const zone of Object.keys(bounds) as Zone[]) {
      const [left, right, top, bottom] = bounds[zone];
      for (const [index, home] of homePositions[zone].entries()) {
        for (let time = 0; time < 96_000; time += 500) {
          const pose = officePose(home, zone, index, time);
          expect(pose.x >= left && pose.x <= right && pose.y >= top && pose.y <= bottom).toBe(true);
        }
      }
    }
  });

  it('keeps staff working most of the time and gives conversation partners distinct seats', () => {
    const home = homePositions.admin[0];
    const samples = Array.from({ length: 70 }, (_, index) =>
      officePose(home, 'admin', 0, index * 1_000),
    );
    expect(samples.filter((pose) => pose.posture === 'working').length).toBeGreaterThan(48);

    const first = officePose(home, 'admin', 0, 15_000, undefined, { key: 0, side: 0 });
    const second = officePose(homePositions.admin[1], 'admin', 1, 15_000, undefined, {
      key: 0,
      side: 1,
    });
    expect(first).toMatchObject({ posture: 'talking', facing: 'right' });
    expect(second).toMatchObject({ posture: 'talking', facing: 'left' });
    expect(first.x).not.toBe(second.x);
  });

  it('lets crew rest at an assigned bed with a sleeping pose', () => {
    const station = stationForEmployee(
      stations.find((stop) => stop.id === 'crew-rest')!,
      1,
    );
    const home = homePositions.crew[1];
    expect(officePose(home, 'crew', 1, 8_000, { started: 0, from: home, station })).toMatchObject({
      x: station.x,
      y: station.y,
      posture: 'sleeping',
      label: 'Resting in bed',
    });
  });
});
