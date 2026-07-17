export interface TourStepDef {
  /** CSS selector matching a `data-tour="..."` attribute in the DOM. */
  target: string;
  title: string;
  description: string;
  /** If set, this step only shows for users holding one of these roles. */
  roles?: string[];
}

/** Context an already-resolved tour launch has available to build its route. */
export interface TourRouteContext {
  projectId?: number;
  tradePackageId?: number;
}

export interface TourDef {
  key: string;
  label: string;
  description: string;
  /** Which "Guided Tours" page section this tour is listed under. */
  group: string;
  /** If set, the whole tour only runs for users holding one of these roles. */
  roles?: string[];
  steps: TourStepDef[];
  /**
   * What accessible record this tour's destination route needs before it can
   * be launched. Absent means the route is fixed and needs nothing (e.g. the
   * dashboard or the org-wide projects list). See lib/tours/launch.ts.
   */
  requires?: 'project' | 'trade-package';
  /** Builds the destination route for this tour from resolved context. */
  route: (ctx: TourRouteContext) => string;
}
