export interface TourStepDef {
  /** CSS selector matching a `data-tour="..."` attribute in the DOM. */
  target: string;
  title: string;
  description: string;
  /** If set, this step only shows for users holding one of these roles. */
  roles?: string[];
}

export interface TourDef {
  key: string;
  label: string;
  description: string;
  /** If set, the whole tour only runs for users holding one of these roles. */
  roles?: string[];
  steps: TourStepDef[];
}
