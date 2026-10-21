export interface TripTicket {
  issuer: {
    name: string | null;
    address: string | null;
    contact_phone: string | null;
    contact_email: string | null;
    logo_url: string | null;
  };
  trip: {
    id: string;
    reference: string;
    status: string;
    dispatch_date: string | null;
    start_off_at: string | null;
    origin: string | null;
    destination: string | null;
    cargo: string | null;
    weight_kg: number | null;
    pieces: number | null;
    distance_km: number | null;
  };
  crew: {
    driver: string | null;
    licence_no: string | null;
    helper_1: string | null;
    helper_2: string | null;
    more_helpers: string[];
    partner: string | null;
  };
  vehicle: { plate: string | null; model: string | null; type: string | null };
  customer: { name: string | null; contact: string | null };
  allowance_cents: number | null;
  toll_cents: number | null;
  checklist: {
    section: string;
    items: { key: string; label: string; answer: 'yes' | 'no' | 'na' | null }[];
  }[];
  checked: { at: string | null; by: string | null; remarks: string | null };
}
