# Failed Analysis

## What it looks like

If an analysis cannot be completed, its status becomes **Failed**, and the
floating progress indicator (or the analysis history list) shows "Analysis
failed" with a short, plain-language error message.

## Common reasons

- The document could not be read as text (for example, an unreadable scan).
- The AI response was cut off before completing (shown as a truncation message)
  — in this case, re-running from scratch is required; the saved partial
  response cannot be repaired.
- A temporary problem with the AI service.

## What to do

1. Try **Re-parse** first if the message suggests the saved response might
   still be usable — this does not use any additional AI usage.
2. If re-parsing is not offered or does not help, start a new analysis.
3. If the document itself may be the problem (poor scan quality, corrupted
   file), try re-uploading a cleaner version of the document.
4. If failures continue, contact your Super Admin — see
   [Troubleshooting: AI analysis failed](../troubleshooting/ai-analysis.md).

## Related

- [AI Analysis](../contracts/ai-analysis.md)
- [Limitations](limitations.md)
