<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Office;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * The document vault (A3.8).
 *
 * Everything is gated on manage-employees. These are contracts, passports and
 * medical notes; view-attendance is not the right key for them, and an employee
 * reaching their own is a self-service feature that does not exist yet rather
 * than something to leave a door open for.
 *
 * Downloads are streamed through this controller rather than served from
 * `public/`. A guessable URL that hands out a passport scan without a session
 * is the one mistake in this feature that would actually matter.
 */
class EmployeeDocumentController extends Controller
{
    protected function companyId(): int
    {
        return auth()->user()->company_id ?? Office::value('company_id');
    }

    protected function authorizeEmployee(Employee $employee): void
    {
        abort_unless($employee->company_id === $this->companyId(), 403);
    }

    public function index(Employee $employee)
    {
        $this->authorizeEmployee($employee);

        return view('employees.documents', [
            'employee'  => $employee,
            // Expiring first: the list is read to find what needs chasing, and
            // sorting by upload date buries exactly that.
            'documents' => $employee->documents()
                ->orderByRaw('expires_on IS NULL, expires_on ASC')
                ->get(),
        ]);
    }

    public function store(Request $request, Employee $employee)
    {
        $this->authorizeEmployee($employee);

        $data = $request->validate([
            'type'  => ['required', Rule::in(array_keys(EmployeeDocument::TYPES))],
            'title' => 'required|string|max:200',
            // Office formats and images, capped at 10MB. No archives and no
            // executables: this is a filing cabinet, not a file share, and a zip
            // nobody can preview is a zip nobody opens.
            'file'  => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx',
            'issued_on'  => 'nullable|date',
            'expires_on' => 'nullable|date|after_or_equal:issued_on',
            'notes' => 'nullable|string|max:500',
        ], [
            'expires_on.after_or_equal' => 'A document cannot expire before it was issued.',
        ]);

        $file = $request->file('file');

        $document = $employee->documents()->create([
            'company_id'    => $employee->company_id,
            'type'          => $data['type'],
            'title'         => $data['title'],
            // Hashed name so two people uploading "passport.pdf" cannot
            // overwrite each other; the real name is kept alongside.
            'path'          => $file->store('employee-documents/' . $employee->id, EmployeeDocument::DISK),
            'original_name' => $file->getClientOriginalName(),
            'mime_type'     => $file->getClientMimeType(),
            'size_bytes'    => $file->getSize(),
            'issued_on'     => $data['issued_on'] ?? null,
            'expires_on'    => $data['expires_on'] ?? null,
            'notes'         => $data['notes'] ?? null,
            'uploaded_by_user_id' => auth()->id(),
        ]);

        ActivityLog::record(
            event: ActivityLog::ACCOUNT_CHANGED,
            description: "Document \"{$document->title}\" added to {$employee->full_name}",
            subject: $employee,
        );

        return back()->with('success', 'Document uploaded.');
    }

    public function download(Employee $employee, EmployeeDocument $document)
    {
        $this->authorizeEmployee($employee);

        // Belt and braces: the route nests the document under the employee, and
        // this refuses a document id from another record pasted into the URL.
        abort_unless($document->employee_id === $employee->id, 404);
        abort_unless(Storage::disk(EmployeeDocument::DISK)->exists($document->path), 404);

        return Storage::disk(EmployeeDocument::DISK)
            ->download($document->path, $document->original_name);
    }

    public function destroy(Employee $employee, EmployeeDocument $document)
    {
        $this->authorizeEmployee($employee);
        abort_unless($document->employee_id === $employee->id, 404);

        $title = $document->title;

        // The model deletes the file on the way out.
        $document->delete();

        ActivityLog::record(
            event: ActivityLog::ACCOUNT_CHANGED,
            description: "Document \"{$title}\" removed from {$employee->full_name}",
            subject: $employee,
        );

        return back()->with('success', 'Document removed.');
    }
}
