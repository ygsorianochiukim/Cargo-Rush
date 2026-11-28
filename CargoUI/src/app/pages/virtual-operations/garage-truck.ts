import { ChangeDetectionStrategy, Component } from '@angular/core';
import { box, polygon, project, SceneVertex } from './office-projection';

const origin = project({ x: 0, y: 0 });
export const truckBounds = { left: 58, top: 133, width: 85, height: 142 };

function face(fill: string, ...vertices: SceneVertex[]) {
  return { fill, points: polygon(vertices) };
}

function axle(x: number, y: number) {
  return project({ x, y }, 7);
}

const farWheel = axle(-16, -13);
const nearWheels = [-92, -77, -17].map((y) => axle(23, y));
const lamps = [-14, 14].flatMap((x) => [
  face('#24384a', [x - 4, 0, 7], [x + 4, 0, 7], [x + 4, 0, 13], [x - 4, 0, 14]),
  face('#fff5d3', [x - 3, 0.15, 9], [x + 3, 0.15, 9], [x + 3, 0.15, 12], [x - 3, 0.15, 12]),
]);
const body = [
  ...box(-16, -105, 32, 105, 13, ['#596673', '#344555', '#273744'], 7).faces,
  ...box(-20, -105, 40, 71, 39, ['#f1f5f5', '#b7c8d0', '#9bb3be'], 15).faces,
  face('#fbfdfb', [-17, -102, 39.2], [17, -102, 39.2], [17, -38, 39.2], [-17, -38, 39.2]),
  face('#487c9d', [14, -100, 39.4], [18.7, -100, 39.4], [18.7, -41, 39.4], [14, -41, 39.4]),
  ...[-101, -95, -40].map((y) =>
    face('#d2dfe3', [-20, y, 39.5], [20, y, 39.5], [20, y + 1.3, 39.5], [-20, y + 1.3, 39.5]),
  ),
  face('#244769', [20.2, -104, 15.5], [20.2, -35, 15.5], [20.2, -35, 19], [20.2, -104, 19]),
  face('#355d85', [20.2, -89, 25], [20.2, -38, 25], [20.2, -38, 33], [20.2, -76, 33]),
  face('#6ca0be', [20.25, -81, 25], [20.25, -38, 25], [20.25, -38, 27.3], [20.25, -78, 27.3]),
  ...[-103, -37].map((y) =>
    face('#ccd9df', [20.3, y, 19], [20.3, y + 1.5, 19], [20.3, y + 1.5, 39], [20.3, y, 39]),
  ),
  ...box(14, -61, 7, 19, 12, ['#8ca1ac', '#5b7180', '#435767'], 8).faces,
  ...box(-22, -12, 4, 4, 25, ['#6e8590', '#34475a', '#263748'], 22).faces,
  face(
    '#a8bdc8',
    [19, -30, 10],
    [19, 0, 10],
    [19, 0, 20],
    [19, -9, 31],
    [19, -28, 31],
    [19, -30, 22],
  ),
  face('#edf4f7', [-19, -28, 31], [19, -28, 31], [19, -9, 31], [-19, -9, 31]),
  face('#f2f6f7', [-19, 0, 8], [19, 0, 8], [19, 0, 20], [19, -9, 31], [-19, -9, 31], [-19, 0, 20]),
  face('#3a566b', [-16.5, -1.5, 21.8], [16.5, -1.5, 21.8], [15, -7.7, 29.7], [-15, -7.7, 29.7]),
  face('#25435e', [-14.4, -1.65, 22], [14.4, -1.65, 22], [13.5, -7.35, 29.3], [-13.5, -7.35, 29.3]),
  face('#6494ae', [-13.8, -2, 22.4], [-1.7, -2, 22.4], [5.5, -6.85, 28.7], [-12.7, -6.85, 28.7]),
  face('#9abaCB', [-16, -0.2, 18], [16, -0.2, 18], [16, -0.45, 20], [-16, -0.45, 20]),
  face('#46677d', [19.1, -25, 19], [19.1, -3.7, 19], [19.1, -10, 28.6], [19.1, -25, 28.6]),
  face('#1e3b53', [19.2, -22, 20.5], [19.2, -6, 20.5], [19.2, -11, 27.3], [19.2, -22, 27.3]),
  face('#7296a8', [19.25, -24, 16.8], [19.25, -18, 16.8], [19.25, -18, 17.8], [19.25, -24, 17.8]),
  ...box(18.4, -26, 2.3, 9, 11, ['#829aa7', '#506775', '#394f60'], 9).faces,
  ...box(19, -10, 4, 4, 25, ['#8a9da5', '#3d5464', '#273849'], 21).faces,
  ...box(-20, -3, 40, 3.8, 8.5, ['#b9c8ce', '#697d88', '#4d6571'], 5).faces,
  face('#24384a', [-10.5, 0.1, 9], [10.5, 0.1, 9], [11.5, 0.1, 15.5], [-11.5, 0.1, 15.5]),
  ...[10, 11.9, 13.8].map((z) =>
    face('#718794', [-9.7, 0.2, z], [9.7, 0.2, z], [9.7, 0.2, z + 0.65], [-9.7, 0.2, z + 0.65]),
  ),
  face('#e9eff0', [-4, 0.85, 5.9], [4, 0.85, 5.9], [4, 0.85, 7.5], [-4, 0.85, 7.5]),
  ...lamps,
];

@Component({
  selector: 'app-garage-truck',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { 'aria-hidden': 'true', style: 'display: block; pointer-events: none' },
  template: `
    <svg [attr.viewBox]="viewBox" style="display:block;width:100%;height:auto">
      <polygon [attr.points]="shadow" fill="#263f4e" opacity=".28" />
      <ellipse [attr.cx]="far.x" [attr.cy]="far.y" rx="4.2" ry="6.4" fill="#242f38" />
      @for (wheel of near; track $index) {
        <ellipse [attr.cx]="wheel.x" [attr.cy]="wheel.y" rx="5" ry="7.2" fill="#1e2932" />
        <circle
          [attr.cx]="wheel.x + 1.5"
          [attr.cy]="wheel.y + 2"
          r="3.1"
          fill="#8fa1ae"
          stroke="#364551"
          stroke-width=".9"
        />
        <circle [attr.cx]="wheel.x + 1.5" [attr.cy]="wheel.y + 2" r="1.1" fill="#506474" />
      }
      @for (face of bodyFaces; track $index) {
        <polygon
          [attr.points]="face.points"
          [attr.fill]="face.fill"
          stroke="#304659"
          stroke-width=".5"
          stroke-opacity=".43"
          stroke-linejoin="round"
        />
      }
    </svg>
  `,
})
export class GarageTruck {
  protected readonly viewBox = `${origin.x - truckBounds.left} ${origin.y - truckBounds.top} ${truckBounds.width} ${truckBounds.height}`;
  protected readonly shadow = polygon([
    [-22, -103, 0],
    [20, -107, 0],
    [27, -104, 0],
    [27, -3, 0],
    [20, 4, 0],
    [-16, 3, 0],
    [-22, -4, 0],
  ]);
  protected readonly far = farWheel;
  protected readonly near = nearWheels;
  protected readonly bodyFaces = body;
}
