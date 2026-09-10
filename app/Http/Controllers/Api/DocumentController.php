<?php

namespace App\Http\Controllers\Api;

use App\Models\EmployeeDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The employee's own filing cabinet (B3.7).
 *
 * The vault itself is A3.8 and has been HR's since it shipped; what did not
 * exist was any way for the person the documents are *about* to read them. The
 * web controller says as much in its own header — "an employee reaching their
 * own is a self-service feature that does not exist yet". This is that feature,
 * and it is deliberately read-only: filing is HR's job and sits behind
 * `manage-employees`, so nothing here uploads, edits or deletes.
 *
 * Scope is the caller's own employee record and nothing else. There is no
 * employee id in any route — not as a parameter to be tampered with, and not as
 * one to be forgotten in a `where`.
 */
class DocumentController extends ApiController
{
    /**
     * Everything filed against the caller.
     *
     * Expiring first, matching the web list: this is read to find what needs
     * renewing, and ordering by upload date buries exactly that.
     */
    public function index(): JsonResponse
    {
        $employee = $this->employee();

        $documents = $employee->documents()
            ->orderByRaw('expires_on IS NULL, expires_on ASC')
            ->get();

        return $this->ok([
            'documents' => $documents->map(fn (EmployeeDocument $doc) => $this->payload($doc))->values(),
            // Counted here rather than left to the app: the badge on the tab
            // and the list it opens must not be able to disagree.
            'expiring_soon' => $documents->filter->expiresSoon()->count(),
            'expired'       => $documents->filter->hasExpired()->count(),
        ]);
    }

    /**
     * The file itself.
     *
     * The one endpoint in the API that does not answer with `ok` — the body is
     * the document. Failures still do, so a client parses JSON only when the
     * status says something went wrong.
     */
    public function download(EmployeeDocument $document): StreamedResponse|JsonResponse
    {
        $employee = $this->employee();

        // 404 rather than 403 for somebody else's document. A 403 would confirm
        // that a given id exists, which is the one bit of information worth
        // withholding when the ids are sequential and the files are passports.
        if ($document->employee_id !== $employee->id) {
            return $this->fail('not_found', __('api.document_not_found'), 404);
        }

        if (! $document->path || ! Storage::disk(EmployeeDocument::DISK)->exists($document->path)) {
            // The row outliving its file means a restore brought back the
            // database without `storage/app/employee-documents/`. Say so plainly
            // rather than streaming nothing — see the deployment guide.
            return $this->fail('file_missing', __('api.document_missing'), 404);
        }

        return Storage::disk(EmployeeDocument::DISK)
            ->download($document->path, $document->original_name);
    }

    /**
     * What the app is told about a document.
     *
     * `notes` and the uploader are deliberately absent. Notes is an HR-written
     * field on an HR screen — the place somebody records why a visa is being
     * chased or what a medical note qualifies — and the employee is the subject
     * of that commentary, not its audience. The uploader names a colleague and
     * answers no question the employee has.
     */
    protected function payload(EmployeeDocument $document): array
    {
        return [
            'id'            => $document->id,
            'type'          => $document->type,
            'type_label'    => $document->type_label,
            'title'         => $document->title,
            'original_name' => $document->original_name,
            'mime_type'     => $document->mime_type,
            'size_bytes'    => (int) $document->size_bytes,
            'size_label'    => $document->size_label,
            'issued_on'     => $document->issued_on?->toDateString(),
            'expires_on'    => $document->expires_on?->toDateString(),
            // none | valid | soon | expired — the same four the web badge uses,
            // so one document cannot be "expiring" on a phone and fine on a desk.
            'expiry_state'  => $document->expiry_state,
        ];
    }
}
