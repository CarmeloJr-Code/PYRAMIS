<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use Symfony\Component\HttpFoundation\Response;

/**
 * The photo of an expense's receipt, for a manager matching it to the record.
 */
class ExpenseReceiptController extends Controller
{
    /**
     * Serve the stored photo inline.
     *
     * Behind access-expenses, like the list it is shown in.
     */
    public function __invoke(Expense $expense): Response
    {
        abort_unless($expense->hasReceipt(), 404);

        $bytes = base64_decode((string) $expense->receipt, strict: true);

        abort_if($bytes === false, 404);

        return response($bytes, 200, [
            'Content-Type' => $expense->receipt_mime,
            'Content-Disposition' => 'inline; filename="expense-'.$expense->id.'-receipt"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
