// Centralized tour launch resolver — the single place that decides whether a
// tour can actually run right now and, if so, which route to send the user
// to. Individual Start/Restart buttons never do their own prerequisite
// checks; they all call resolveTourLaunch() and act on its result.
//
// Every project/trade-package id used here comes from a GET the backend has
// already scoped to the authenticated user's own organization (ProjectController
// ::index() filters by organization_id for anyone who isn't Super Admin/Admin).
// This resolver never accepts or trusts an id supplied by a query parameter —
// it only ever picks from what the backend already told us the user can see.
import api from '@/lib/api';
import { getTour } from './registry';

export interface ResolvedProject {
  id: number;
  name: string;
}

export type TourLaunchResult =
  | { status: 'ready'; route: string }
  | { status: 'unknown-tour' }
  | { status: 'missing-project' }
  | { status: 'missing-trade-package'; project: ResolvedProject };

interface ProjectListItem {
  id: number;
  name: string;
}

// GET /projects already orders latest-first (Project::latest()), so the
// first page is "most recently created accessible project" with no extra
// sorting needed here.
async function fetchAccessibleProjects(): Promise<ProjectListItem[]> {
  try {
    const res = await api.get('/projects');
    return res.data?.data ?? [];
  } catch {
    return [];
  }
}

async function fetchFirstTradePackage(projectId: number): Promise<{ id: number } | null> {
  try {
    const res = await api.get(`/projects/${projectId}/documents/module/subcontracts`);
    return res.data?.trade_packages?.[0] ?? null;
  } catch {
    return null;
  }
}

export async function resolveTourLaunch(tourKey: string): Promise<TourLaunchResult> {
  const def = getTour(tourKey);
  if (!def) return { status: 'unknown-tour' };

  if (!def.requires) {
    return { status: 'ready', route: def.route({}) };
  }

  const projects = await fetchAccessibleProjects();
  if (projects.length === 0) return { status: 'missing-project' };

  if (def.requires === 'project') {
    return { status: 'ready', route: def.route({ projectId: projects[0].id }) };
  }

  // requires === 'trade-package' — scan the same authorized project list,
  // most-recent first, for the first one that actually has a trade package.
  // A project with none isn't a valid launch target for this tour, so it's
  // skipped rather than sending the user into a workspace URL that can't
  // resolve to a real record.
  for (const project of projects) {
    const tradePackage = await fetchFirstTradePackage(project.id);
    if (tradePackage) {
      return { status: 'ready', route: def.route({ projectId: project.id, tradePackageId: tradePackage.id }) };
    }
  }

  return { status: 'missing-trade-package', project: projects[0] };
}
