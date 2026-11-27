import { ChangeDetectionStrategy, Component } from '@angular/core';
import { box, polygon, project } from './office-projection';

const origin = project({ x: 0, y: 0 });
export const truckBounds = { left: 50, top: 106, width: 75, height: 111 };

@Component({
  selector: 'app-garage-truck',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { 'aria-hidden': 'true', style: 'display: block; pointer-events: none' },
  template: `
    <svg [attr.viewBox]="viewBox" style="display:block;width:100%;height:auto">
      <polygon [attr.points]="shadow" fill="#354858" opacity=".25" />
      @for (wheel of wheels; track wheel.x) {
        <ellipse [attr.cx]="wheel.x" [attr.cy]="wheel.y" rx="4.5" ry="6.5" fill="#263541" />
        <ellipse [attr.cx]="wheel.x" [attr.cy]="wheel.y" rx="2.2" ry="3.5" fill="#80929a" />
      }
      @for (face of faces; track $index) {
        <polygon
          [attr.points]="face.points"
          [attr.fill]="face.fill"
          stroke="#30495a"
          stroke-width=".65"
          stroke-opacity=".55"
        />
      }
      <polygon [attr.points]="sideStripe" fill="#387eaa" />
      <polygon [attr.points]="windscreen" fill="#45718e" stroke="#28475d" stroke-width=".9" />
      <polygon [attr.points]="grille" fill="#39505f" />
      @for (lamp of lamps; track lamp.x) {
        <ellipse [attr.cx]="lamp.x" [attr.cy]="lamp.y" rx="2.7" ry="1.7" fill="#ffe9ab" />
      }
    </svg>
  `,
})
export class GarageTruck {
  protected readonly viewBox = `${origin.x - truckBounds.left} ${origin.y - truckBounds.top} ${truckBounds.width} ${truckBounds.height}`;
  protected readonly shadow = polygon([
    [-22, -101, 0],
    [23, -101, 0],
    [23, 3, 0],
    [-22, 3, 0],
  ]);
  protected readonly wheels = [-83, -71, -16].map((y) => project({ x: 19, y }, 6));
  protected readonly faces = [
    box(-17, -99, 34, 99, 9, ['#596f7b', '#415562', '#304552'], 5),
    box(-19, -100, 38, 73, 26, ['#f9faf3', '#d3dee0', '#b7cbd3'], 9),
    box(-18, -25, 36, 23, 20, ['#f2f6f3', '#e1e9e9', '#b5c9d4'], 8),
    box(-18, -9, 36, 9, 10, ['#edf3f4', '#d7e4e8', '#a5bfcb'], 6),
  ].flatMap((part) => part.faces);
  protected readonly sideStripe = polygon([
    [19, -98, 14],
    [19, -28, 14],
    [19, -28, 18],
    [19, -98, 18],
  ]);
  protected readonly windscreen = polygon([
    [-13, -3, 12],
    [13, -3, 12],
    [12, -7, 19],
    [-12, -7, 19],
  ]);
  protected readonly grille = polygon([
    [-9, 0, 6.7],
    [9, 0, 6.7],
    [9, 0, 9],
    [-9, 0, 9],
  ]);
  protected readonly lamps = [-13, 13].map((x) => project({ x, y: 0 }, 8));
}
