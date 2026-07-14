# File Restrictions

## Supported file types

SureSign accepts common document and image formats for general uploads,
including PDF, Word (DOC/DOCX), Excel (XLS/XLSX/CSV), plain text, and standard
image formats (JPG, PNG, WEBP). Document templates (used by Admins) are
restricted to Word and PDF formats.

## Maximum file size

Your organisation's administrator sets the maximum upload size (50MB by
default). If your file is larger, the upload will be rejected.

## Why a file might be rejected

SureSign checks every upload for a supported and safe file before storing it.
A file may be rejected if:

- Its type is not supported.
- Its contents do not actually match its file extension.
- Its file name looks unsafe (for example, it contains characters used to try
  to escape the intended folder).
- It is a Word or Excel file that looks like it could overwhelm the system when
  opened (an oversized or unusually compressed file).

In every case, SureSign shows a short, generic message (for example "This file
type is not supported.") rather than technical detail, and nothing is stored.

## What to do if your file is rejected

- Confirm the file is genuinely the type it claims to be (for example, a `.pdf`
  file that is really a renamed image will be rejected).
- Try re-saving or re-exporting the file from its original application.
- If you believe a legitimate file is being rejected, contact your Super Admin.

## Related

- [Uploading](uploading.md)
- [Troubleshooting: Upload rejected](../troubleshooting/uploads.md)
