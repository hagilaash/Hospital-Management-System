<?php

namespace App\Service;

use App\Common\Input;
use App\DTO\BillDTO;
use App\DTO\PagedListDTO;
use App\DTO\PatientDTO;
use App\DTO\PatientListDTO;
use App\Repository\BillingRepository;

/**
 * Business logic for Billing and Payments.
 *
 * - Reuses PatientService for patient search/lookup: no patient is ever created here.
 * - Cleans and validates every field before it reaches the database. Amounts are
 *   handled as 2-decimal STRINGS (never floats). PostgreSQL then recalculates every
 *   figure itself (subtotal, tax, total, balance, status), so a total sent by the
 *   browser is never used and cannot be tampered with.
 * - Payment safety (no overpayment, no payment on a cancelled or paid bill, no
 *   duplicate submission) is enforced in the database under a row lock; this class
 *   only gives clear messages and attaches them to the right form field.
 *
 * Write operations return one array shape:
 *
 *   [
 *     'success'           => bool,
 *     'message'           => string,                  // safe to show to the user
 *     'errors'            => array<string, string>,   // fieldName => message
 *     'bill_id'           => ?int,
 *     'bill_number'       => ?string,
 *     'payment_id'        => ?int,
 *     'payment_reference' => ?string,
 *     'duplicate'         => bool,                    // true: this payment had already been recorded
 *   ]
 */
class BillingService
{
    public const STATUSES = ['Pending', 'Partially Paid', 'Paid', 'Cancelled'];
    public const STATUS_FILTERS = ['all', 'Pending', 'Partially Paid', 'Paid', 'Cancelled'];
    public const METHODS = ['Cash', 'Card', 'UPI', 'Bank Transfer'];

    public const PER_PAGE = 10;
    public const RECENT_BILLS = 5;
    public const MAX_ITEMS = 50;
    public const MIN_ITEM_ROWS = 5;

    private const MESSAGE_VALIDATION = 'Please correct the errors below';
    private const MESSAGE_NOT_FOUND = 'Bill not found';
    private const MESSAGE_NOT_SIGNED_IN = 'You must be signed in to perform this action';

    public function __construct(
        private BillingRepository $billingRepository,
        private PatientService $patientService,
    ) {
    }

    // ------------------------------------------------------------------
    // Lookups
    // ------------------------------------------------------------------

    /**
     * Figures for the dashboard and the latest bills.
     *
     * @return array{summary: array<string, int|string>, recent: list<BillDTO>}
     */
    public function getDashboard(): array
    {
        $row = $this->billingRepository->getBillingSummary();

        $summary = [];
        foreach (['total_bills', 'paid_bills', 'pending_bills', 'partially_paid_bills', 'cancelled_bills'] as $key) {
            $summary[$key] = (int) ($row[$key] ?? 0);
        }
        foreach (['total_billed', 'total_collected', 'total_outstanding'] as $key) {
            $summary[$key] = number_format((float) ($row[$key] ?? 0), 2, '.', '');
        }

        return [
            'summary' => $summary,
            'recent' => $this->billingRepository->getBills(null, null, null, null, self::RECENT_BILLS, 0),
        ];
    }

    /**
     * Bills, newest first, 10 per page.
     * Filters: status (whitelisted), search, date_from, date_to (all optional).
     */
    public function getBills(?string $status, ?string $search, ?string $dateFrom, ?string $dateTo, int $page = 1): PagedListDTO
    {
        $status = Input::whitelist($status, self::STATUS_FILTERS, 'all');
        $search = Input::search($search);
        $from = Input::dateOrNull($dateFrom);
        $to = Input::dateOrNull($dateTo);
        if (null !== $from && null !== $to && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        $total = $this->billingRepository->countBills($status, $search, $from, $to);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $totalPages);

        $items = $total > 0
            ? $this->billingRepository->getBills($status, $search, $from, $to, self::PER_PAGE, ($page - 1) * self::PER_PAGE)
            : [];

        return new PagedListDTO(
            $items,
            $total,
            $page,
            self::PER_PAGE,
            $totalPages,
            ['status' => $status, 'search' => $search, 'date_from' => $from, 'date_to' => $to]
        );
    }

    public function getBill(int $billId): ?BillDTO
    {
        if ($billId < 1) {
            return null;
        }

        return $this->billingRepository->getBill($billId);
    }

    /** Active patients matching a search (patient ID, name or phone), 10 per page. */
    public function searchPatients(?string $search, int $page = 1): PatientListDTO
    {
        return $this->patientService->getPatientsList($search, PatientService::STATUS_ACTIVE, $page);
    }

    public function getPatient(int $patientId): ?PatientDTO
    {
        return $this->patientService->getPatient($patientId);
    }

    /**
     * Consultations that were sent to billing and have no live bill yet. Both
     * filters are optional. Empty when the OP Consultation table does not exist.
     *
     * @return list<array<string, mixed>>
     */
    public function getBillableConsultations(?int $patientId, ?int $consultationId = null): array
    {
        return $this->billingRepository->getBillableConsultations($patientId, $consultationId, 20);
    }

    /** A random token that makes one payment form submittable only once. */
    public function newPaymentKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    // ------------------------------------------------------------------
    // Create a bill
    // ------------------------------------------------------------------

    public function createBill(BillDTO $input, string $performedBy): array
    {
        $performedBy = trim($performedBy);
        if ('' === $performedBy) {
            return $this->failure(self::MESSAGE_NOT_SIGNED_IN);
        }

        $errors = [];
        $bill = new BillDTO();

        if (null === $input->patientId || $input->patientId < 1) {
            $errors['patientId'] = 'Select a patient';
        }
        $bill->patientId = $input->patientId;
        $bill->consultationId = null !== $input->consultationId && $input->consultationId > 0 ? $input->consultationId : null;

        // Items
        if ([] === $input->items) {
            $errors['items'] = 'Add at least one bill item';
        } elseif (count($input->items) > self::MAX_ITEMS) {
            $errors['items'] = 'A bill can have at most '.self::MAX_ITEMS.' items';
        } else {
            foreach ($input->items as $index => $row) {
                $problems = [];
                $description = Input::clean((string) ($row['description'] ?? ''));
                $quantity = trim((string) ($row['quantity'] ?? ''));
                $price = Input::money((string) ($row['unit_price'] ?? ''), 7);

                if ('' === $description) {
                    $problems[] = 'Description is required';
                } elseif (mb_strlen($description) > 200) {
                    $problems[] = 'Description must be 200 characters or fewer';
                }
                if (1 !== preg_match('/^\d{1,3}$/', $quantity) || (int) $quantity < 1) {
                    $problems[] = 'Quantity must be a whole number from 1 to 999';
                }
                if (null === $price) {
                    $problems[] = 'Unit price must be an amount with at most 2 decimals';
                }

                if ([] !== $problems) {
                    $errors['item_'.$index] = implode('. ', $problems);
                    continue;
                }
                $bill->items[] = ['description' => $description, 'quantity' => (string) (int) $quantity, 'unit_price' => (string) $price];
            }
        }

        // Discount
        $discountText = trim($input->discountAmount);
        $discount = '' === $discountText ? '0.00' : Input::money($discountText, 8);
        if (null === $discount) {
            $errors['discountAmount'] = 'Enter a valid discount amount (at most 2 decimals)';
        }
        $reason = Input::nullIfEmpty(Input::clean($input->discountReason));
        if (null !== $reason && mb_strlen($reason) > 255) {
            $errors['discountReason'] = 'The discount reason must be 255 characters or fewer';
        } elseif (null !== $discount && '0.00' !== $discount && null === $reason) {
            $errors['discountReason'] = 'A reason is required when a discount is given';
        }
        $bill->discountAmount = $discount ?? '0.00';
        $bill->discountReason = '0.00' === $discount ? null : $reason;

        // Tax
        $taxText = trim($input->taxPercent);
        $tax = '' === $taxText ? '0.00' : Input::percent($taxText);
        if (null === $tax) {
            $errors['taxPercent'] = 'Tax must be between 0 and 100 (at most 2 decimals)';
        }
        $bill->taxPercent = $tax ?? '0.00';

        // Notes
        $notes = Input::nullIfEmpty(Input::cleanMultiline($input->notes));
        if (null !== $notes && mb_strlen($notes) > 500) {
            $errors['notes'] = 'Notes must be 500 characters or fewer';
        }
        $bill->notes = $notes;

        if ([] !== $errors) {
            return $this->failure(self::MESSAGE_VALIDATION, $errors);
        }

        $db = $this->billingRepository->createBill($bill, $performedBy);

        $result = $this->resultFrom($db, $this->createFieldMap());
        $result['bill_id'] = isset($db['bill_id']) ? (int) $db['bill_id'] : null;
        $result['bill_number'] = isset($db['bill_number']) ? (string) $db['bill_number'] : null;
        $this->mapItemMessage($result);

        return $result;
    }

    // ------------------------------------------------------------------
    // Payments and cancellation
    // ------------------------------------------------------------------

    public function recordPayment(
        int $billId,
        ?string $amount,
        ?string $method,
        ?string $transactionRef,
        ?string $idempotencyKey,
        string $performedBy,
    ): array {
        if ($billId < 1) {
            return $this->failure(self::MESSAGE_NOT_FOUND);
        }
        $performedBy = trim($performedBy);
        if ('' === $performedBy) {
            return $this->failure(self::MESSAGE_NOT_SIGNED_IN);
        }

        $errors = [];

        $money = Input::money($amount, 8);
        if (null === $money || '0.00' === $money) {
            $errors['amount'] = 'Enter a payment amount greater than zero (at most 2 decimals)';
        }

        $method = trim((string) $method);
        if (!in_array($method, self::METHODS, true)) {
            $errors['method'] = 'Select a payment method';
        }

        $reference = Input::nullIfEmpty(Input::clean($transactionRef));
        if (null !== $reference && mb_strlen($reference) > 100) {
            $errors['transactionRef'] = 'The transaction reference must be 100 characters or fewer';
        }

        $key = trim((string) $idempotencyKey);
        if (1 !== preg_match('/^[A-Za-z0-9]{16,64}$/', $key)) {
            $errors['form'] = 'The payment form has expired. Please reload the page and try again';
        }

        if ([] !== $errors) {
            return $this->failure($errors['form'] ?? self::MESSAGE_VALIDATION, $errors);
        }

        $db = $this->billingRepository->recordPayment($billId, (string) $money, $method, $reference, $key, $performedBy);

        $result = $this->resultFrom($db, [
            'The payment amount' => 'amount',
            'The payment exceeds' => 'amount',
            'Select a valid payment method' => 'method',
            'The transaction reference' => 'transactionRef',
        ]);
        $result['payment_id'] = isset($db['payment_id']) ? (int) $db['payment_id'] : null;
        $result['payment_reference'] = isset($db['payment_reference']) ? (string) $db['payment_reference'] : null;
        $result['duplicate'] = Input::isTrue($db['duplicate'] ?? false);

        return $result;
    }

    public function cancelBill(int $billId, ?string $reason, string $performedBy): array
    {
        if ($billId < 1) {
            return $this->failure(self::MESSAGE_NOT_FOUND);
        }
        $performedBy = trim($performedBy);
        if ('' === $performedBy) {
            return $this->failure(self::MESSAGE_NOT_SIGNED_IN);
        }

        $reason = Input::nullIfEmpty(Input::clean($reason));
        if (null === $reason) {
            return $this->failure('A cancellation reason is required', ['reason' => 'A cancellation reason is required']);
        }
        if (mb_strlen($reason) > 255) {
            $message = 'The cancellation reason must be 255 characters or fewer';

            return $this->failure($message, ['reason' => $message]);
        }

        return $this->resultFrom($this->billingRepository->cancelBill($billId, $reason, $performedBy), []);
    }

    // ------------------------------------------------------------------
    // Result building
    // ------------------------------------------------------------------

    /** @return array<string, string> database message prefix => form field */
    private function createFieldMap(): array
    {
        return [
            'Add at least one bill item' => 'items',
            'A bill can have at most' => 'items',
            'The bill total' => 'items',
            'The discount' => 'discountAmount',
            'A reason is required when a discount' => 'discountReason',
            'The tax percentage' => 'taxPercent',
            'Notes must' => 'notes',
            'Patient not found' => 'patientId',
            'Inactive patients' => 'patientId',
        ];
    }

    /**
     * success + message from a database row; a refusal that belongs to one field
     * is also attached to that field so the form can show it next to the input.
     *
     * @param array<string, string> $fieldMap
     */
    private function resultFrom(array $db, array $fieldMap): array
    {
        $result = $this->failure((string) ($db['message'] ?? ''));
        $result['success'] = Input::isTrue($db['success'] ?? false);

        if (!$result['success']) {
            foreach ($fieldMap as $prefix => $field) {
                if (str_starts_with($result['message'], $prefix)) {
                    $result['errors'][$field] = $result['message'];
                    break;
                }
            }
        }

        return $result;
    }

    /** "Item 3: quantity ..." from the database belongs to row index 2. */
    private function mapItemMessage(array &$result): void
    {
        if (!$result['success'] && 1 === preg_match('/^Item (\d+):/', $result['message'], $m)) {
            $result['errors']['item_'.((int) $m[1] - 1)] = $result['message'];
        }
    }

    /**
     * The uniform result shape, pre-filled as a failure.
     *
     * @param array<string, string> $errors
     */
    private function failure(string $message, array $errors = []): array
    {
        return [
            'success' => false,
            'message' => $message,
            'errors' => $errors,
            'bill_id' => null,
            'bill_number' => null,
            'payment_id' => null,
            'payment_reference' => null,
            'duplicate' => false,
        ];
    }
}
