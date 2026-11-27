import { aiWorkstations } from './office-furniture-layout';

export const officeAgents = [
  {
    id: 'dispatch',
    name: 'Dispatch Agent',
    question: 'What needs attention in dispatch?',
    ...aiWorkstations[0].seat,
  },
  {
    id: 'fleet',
    name: 'Fleet Agent',
    question: 'How many trucks are available, unavailable, or traveling?',
    ...aiWorkstations[1].seat,
  },
  {
    id: 'office',
    name: 'Office Agent',
    question: 'Give me a staffing and operations summary.',
    ...aiWorkstations[2].seat,
  },
] as const;

export type OfficeAgent = (typeof officeAgents)[number];
export type OfficeDesk = OfficeAgent | { id: 'discord'; name: string; x: number; y: number };
export const discordDesk: OfficeDesk = {
  id: 'discord',
  name: 'Discord Assistant',
  ...aiWorkstations[3].seat,
};
