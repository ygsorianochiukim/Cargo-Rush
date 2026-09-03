import {
  Inspection,
  InspectionItem,
  InspectionPayload,
  MaintenanceJob,
} from '@/models/inspection/inspection.model';

import { api } from '../shared/api.service';

/**
 * On-boarding trips inspection, and unit maintenance.
 *
 * Both are mobile-only capture (DESIGN.md section 5.4): the checklist happens
 * at the vehicle, with the camera and the driver's own eyes.
 */
export const inspectionService = {
  /**
   * The checklist comes from the API rather than being hardcoded here, so the
   * keys a submission is stored under and the keys this screen renders can
   * never drift apart.
   */
  checklist(): Promise<InspectionItem[]> {
    return api.get<InspectionItem[]>('inspections/checklist');
  },

  /**
   * Submit a completed check.
   *
   * Worth reading the response back: `good_to_go` is the API's call, made from
   * the results, and it is the answer the driver actually needs.
   */
  submit(payload: InspectionPayload): Promise<Inspection> {
    return api.post<Inspection>('inspections', payload);
  },

  /** The maintenance jobs booked against this driver's unit. */
  maintenance(vehicleId: string): Promise<MaintenanceJob[]> {
    return api.get<MaintenanceJob[]>(`inspections/vehicles/${vehicleId}/maintenance`);
  },
};
