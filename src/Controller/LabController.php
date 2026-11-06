<?php

namespace App\Controller;

use App\Common\Input;
use App\DTO\LabOrderDTO;
use App\DTO\LabSummaryDTO;
use App\DTO\LabTestDTO;
use App\DTO\PagedListDTO;
use App\Service\AccessControlService;
use App\Service\AuthSessionService;
use App\Service\LabService;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * HTTP layer for the Laboratory (LIS).
 *
 *   Lab staff : dashboard, test catalogue, worklist, sample collection, results, report
 *   Doctors   : "Order lab tests" from an OP Consultation (lab_order*)
 *
 * Authentication is enforced for every route by AuthenticationListener. Each route also declares the
 * permission it needs (defaults: _permission).
 *
 * Every state change is a POST protected by a CSRF token. Token ids:
 *   lab_test_{id|new}, lab_collect_{orderId}, lab_result_{itemId}, lab_cancel_{itemId},
 *   lab_order_{consultationId}, lab_remove_{itemId}
 *
 * Database failures are caught, logged and shown as a generic message; no SQL error text reaches the user.
 */
class LabController extends AbstractController
{
    private const MESSAGE_GENERIC_ERROR = 'Something went wrong while accessing laboratory records. Please try again.';
    private const MESSAGE_CSRF_ERROR = 'Your session has expired. Please try again.';

    public function __construct(
        private LabService $labService,
        private AuthSessionService $authSession,
        private LoggerInterface $logger
    ) {
    }

    // ==================================================================
    // Dashboard
    // ==================================================================

    #[Route('/lab', name: 'lab_dashboard', methods: ['GET'], defaults: ['_permission' => AccessControlService::LAB_VIEW])]
    public function dashboard(): Response
    {
        try {
            $summary = $this->labService->getSummary();
            $pending = $this->labService->getWorklist('pending', null, 1);
        } catch (DbalException $exception) {
            $this->logger->error('Lab dashboard could not be loaded.', ['exception' => $exception]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            $summary = LabSummaryDTO::empty();
            $pending = PagedListDTO::empty(LabService::PER_PAGE, ['view' => 'pending', 'search' => null]);
        }

        return $this->render('lab/index.html.twig', ['summary' => $summary, 'pending' => $pending]);
    }

    // ==================================================================
    // Test catalogue
    // ==================================================================

    #[Route('/lab/tests', name: 'lab_tests', methods: ['GET'], defaults: ['_permission' => AccessControlService::LAB_VIEW])]
    public function tests(Request $request): Response
    {
        $query = $request->query;

        try {
            $list = $this->labService->getTests($query->getString('search'), $query->getString('category'), $query->getString('status'), $query->getInt('page', 1));
        } catch (DbalException $exception) {
            $this->logger->error('Test list could not be loaded.', ['exception' => $exception]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            $list = PagedListDTO::empty(LabService::PER_PAGE, ['search' => null, 'category' => null, 'status' => 'all']);
        }

        return $this->render('lab/tests.html.twig', [
            'list' => $list,
            'categories' => LabService::CATEGORIES,
            'statuses' => LabService::STATUS_FILTERS,
        ]);
    }

    #[Route('/lab/tests/new', name: 'lab_test_new', methods: ['GET', 'POST'], defaults: ['_permission' => AccessControlService::LAB_MANAGE])]
    public function newTest(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->renderTestForm(new LabTestDTO(), null);
        }

        $test = LabTestDTO::fromInput($request->request->all());

        if (!$this->isCsrfTokenValid('lab_test_new', $request->request->getString('_token'))) {
            return $this->renderTestForm($test, null, [], Response::HTTP_BAD_REQUEST, self::MESSAGE_CSRF_ERROR);
        }

        return $this->saveTest(null, $test);
    }

    #[Route('/lab/tests/{id}/edit', name: 'lab_test_edit', requirements: ['id' => '\d{1,9}'], methods: ['GET', 'POST'], defaults: ['_permission' => AccessControlService::LAB_MANAGE])]
    public function editTest(int $id, Request $request): Response
    {
        $existing = $this->loadTest($id);
        if ($existing instanceof Response) {
            return $existing;
        }

        if (!$request->isMethod('POST')) {
            return $this->renderTestForm($existing, $id);
        }

        $test = LabTestDTO::fromInput($request->request->all());
        $test->testId = $id;
        $test->testCode = $existing->testCode;

        if (!$this->isCsrfTokenValid('lab_test_' . $id, $request->request->getString('_token'))) {
            return $this->renderTestForm($test, $id, [], Response::HTTP_BAD_REQUEST, self::MESSAGE_CSRF_ERROR);
        }

        return $this->saveTest($id, $test);
    }

    // ==================================================================
    // Worklist, processing and report
    // ==================================================================

    #[Route('/lab/worklist', name: 'lab_worklist', methods: ['GET'], defaults: ['_permission' => AccessControlService::LAB_VIEW])]
    public function worklist(Request $request): Response
    {
        $query = $request->query;

        try {
            $list = $this->labService->getWorklist($query->getString('view'), $query->getString('search'), $query->getInt('page', 1));
        } catch (DbalException $exception) {
            $this->logger->error('Lab worklist could not be loaded.', ['exception' => $exception]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            $list = PagedListDTO::empty(LabService::PER_PAGE, ['view' => 'pending', 'search' => null]);
        }

        return $this->render('lab/worklist.html.twig', ['list' => $list]);
    }

    #[Route('/lab/orders/{id}', name: 'lab_order_show', requirements: ['id' => '\d{1,9}'], methods: ['GET'], defaults: ['_permission' => AccessControlService::LAB_VIEW])]
    public function showOrder(int $id): Response
    {
        $order = $this->loadOrder($id);
        if ($order instanceof Response) {
            return $order;
        }

        return $this->render('lab/order.html.twig', [
            'order' => $order,
            'sample_types' => LabService::SAMPLE_TYPES,
        ]);
    }

    #[Route('/lab/orders/{id}/report', name: 'lab_report', requirements: ['id' => '\d{1,9}'], methods: ['GET'], defaults: ['_permission' => AccessControlService::LAB_VIEW])]
    public function report(int $id): Response
    {
        $order = $this->loadOrder($id);
        if ($order instanceof Response) {
            return $order;
        }

        return $this->render('lab/report.html.twig', ['order' => $order]);
    }

    #[Route('/lab/orders/{id}/samples', name: 'lab_collect', requirements: ['id' => '\d{1,9}'], methods: ['POST'], defaults: ['_permission' => AccessControlService::LAB_COLLECT])]
    public function collectSample(int $id, Request $request): Response
    {
        $back = $this->redirectToRoute('lab_order_show', ['id' => $id]);

        if (!$this->isCsrfTokenValid('lab_collect_' . $id, $request->request->getString('_token'))) {
            $this->addFlash('error', self::MESSAGE_CSRF_ERROR);

            return $back;
        }

        try {
            $result = $this->labService->collectSample($id, $request->request->getString('sample_type'), $request->request->getString('notes'), $this->currentUsername());
        } catch (DbalException $exception) {
            $this->logger->error('Collecting a sample failed.', ['exception' => $exception, 'order_id' => $id]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return $back;
        }

        $this->flashResult($result);

        return $back;
    }

    #[Route('/lab/items/{id}/result', name: 'lab_result', requirements: ['id' => '\d{1,9}'], methods: ['POST'], defaults: ['_permission' => AccessControlService::LAB_RESULT])]
    public function enterResult(int $id, Request $request): Response
    {
        $back = $this->backToOrder($request);

        if (!$this->isCsrfTokenValid('lab_result_' . $id, $request->request->getString('_token'))) {
            $this->addFlash('error', self::MESSAGE_CSRF_ERROR);

            return $back;
        }

        try {
            $result = $this->labService->enterResult(
                $id,
                $request->request->getString('value'),
                $request->request->getString('flag'),
                $request->request->getString('comment'),
                $this->currentUsername()
            );
        } catch (DbalException $exception) {
            $this->logger->error('Entering a result failed.', ['exception' => $exception, 'item_id' => $id]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return $back;
        }

        $this->flashResult($result);

        return $back;
    }

    #[Route('/lab/items/{id}/cancel', name: 'lab_cancel_item', requirements: ['id' => '\d{1,9}'], methods: ['POST'], defaults: ['_permission' => AccessControlService::LAB_RESULT])]
    public function cancelItem(int $id, Request $request): Response
    {
        $back = $this->backToOrder($request);

        if (!$this->isCsrfTokenValid('lab_cancel_' . $id, $request->request->getString('_token'))) {
            $this->addFlash('error', self::MESSAGE_CSRF_ERROR);

            return $back;
        }

        try {
            $result = $this->labService->cancelItem($id, $request->request->getString('reason'), $this->currentUsername());
        } catch (DbalException $exception) {
            $this->logger->error('Cancelling a test failed.', ['exception' => $exception, 'item_id' => $id]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return $back;
        }

        $this->flashResult($result);

        return $back;
    }

    // ==================================================================
    // Ordering: the doctor's screens, reached from OP Consultation
    // ==================================================================

    /** {id} is the CONSULTATION id. */
    #[Route('/lab/order/{id}', name: 'lab_order', requirements: ['id' => '\d{1,9}'], methods: ['GET'], defaults: ['_permission' => AccessControlService::LAB_ORDER])]
    public function orderTests(int $id, Request $request): Response
    {
        $order = $this->loadConsultationOrder($id);
        if ($order instanceof Response) {
            return $order;
        }

        return $this->renderOrderTests($order, $request->query->getString('q'), Input::positiveInt($request->query->getString('test_id')));
    }

    #[Route('/lab/order/{id}/items', name: 'lab_order_add', requirements: ['id' => '\d{1,9}'], methods: ['POST'], defaults: ['_permission' => AccessControlService::LAB_ORDER])]
    public function addItem(int $id, Request $request): Response
    {
        $order = $this->loadConsultationOrder($id);
        if ($order instanceof Response) {
            return $order;
        }

        $post = $request->request;
        $typed = [
            'test_id' => $post->getString('test_id'),
            'priority' => $post->getString('priority'),
            'clinical_notes' => $post->getString('clinical_notes'),
        ];
        $selectedId = Input::positiveInt($typed['test_id']);

        if (!$this->isCsrfTokenValid('lab_order_' . $id, $post->getString('_token'))) {
            return $this->renderOrderTests($order, '', $selectedId, $typed, [], Response::HTTP_BAD_REQUEST, self::MESSAGE_CSRF_ERROR);
        }

        try {
            $result = $this->labService->addOrderItem($id, $typed['test_id'], $typed['priority'], $typed['clinical_notes'], $this->currentUsername());
        } catch (DbalException $exception) {
            $this->logger->error('Ordering a test failed.', ['exception' => $exception, 'consultation_id' => $id]);

            return $this->renderOrderTests($order, '', $selectedId, $typed, [], Response::HTTP_SERVICE_UNAVAILABLE, self::MESSAGE_GENERIC_ERROR);
        }

        if ($result['success']) {
            $this->addFlash('success', $result['message']);

            return $this->redirectToRoute('lab_order', ['id' => $id]);
        }

        $status = $result['errors'] !== [] ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_CONFLICT;

        return $this->renderOrderTests($order, '', $selectedId, $typed, $result['errors'], $status, $result['message']);
    }

    #[Route('/lab/order/{id}/items/{itemId}/remove', name: 'lab_order_remove', requirements: ['id' => '\d{1,9}', 'itemId' => '\d{1,9}'], methods: ['POST'], defaults: ['_permission' => AccessControlService::LAB_ORDER])]
    public function removeItem(int $id, int $itemId, Request $request): Response
    {
        $back = $this->redirectToRoute('lab_order', ['id' => $id]);

        if (!$this->isCsrfTokenValid('lab_remove_' . $itemId, $request->request->getString('_token'))) {
            $this->addFlash('error', self::MESSAGE_CSRF_ERROR);

            return $back;
        }

        try {
            $result = $this->labService->removeOrderItem($itemId, $this->currentUsername());
        } catch (DbalException $exception) {
            $this->logger->error('Removing a test failed.', ['exception' => $exception, 'item_id' => $itemId]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return $back;
        }

        $this->flashResult($result);

        return $back;
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private function currentUsername(): string
    {
        return $this->authSession->getUsername() ?? '';
    }

    /**
     * @param array{success: bool, message: string, errors: array<string, string>} $result
     */
    private function flashResult(array $result): void
    {
        if ($result['success']) {
            $this->addFlash('success', $result['message']);

            return;
        }

        $detail = array_unique(array_values($result['errors']));
        $this->addFlash('error', $detail === [] || $result['message'] === $detail[0] ? $result['message'] : $result['message'] . ': ' . implode('. ', $detail));
    }

    /** Back to the processing screen of the order the posted form belongs to (hidden field order_id). */
    private function backToOrder(Request $request): RedirectResponse
    {
        $orderId = Input::positiveInt($request->request->getString('order_id'));

        return $orderId !== null ? $this->redirectToRoute('lab_order_show', ['id' => $orderId]) : $this->redirectToRoute('lab_worklist');
    }

    private function saveTest(?int $id, LabTestDTO $test): Response
    {
        try {
            $result = $this->labService->saveTest($id, $test, $this->currentUsername());
        } catch (DbalException $exception) {
            $this->logger->error('Saving a test failed.', ['exception' => $exception, 'test_id' => $id]);

            return $this->renderTestForm($test, $id, [], Response::HTTP_SERVICE_UNAVAILABLE, self::MESSAGE_GENERIC_ERROR);
        }

        if ($result['success']) {
            $this->addFlash('success', $result['message']);

            return $this->redirectToRoute('lab_tests');
        }

        $status = $result['errors'] !== [] ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_CONFLICT;

        return $this->renderTestForm($test, $id, $result['errors'], $status, $result['message']);
    }

    private function loadTest(int $id): LabTestDTO|RedirectResponse
    {
        try {
            $test = $this->labService->getTest($id);
        } catch (DbalException $exception) {
            $this->logger->error('Test could not be loaded.', ['exception' => $exception, 'test_id' => $id]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return $this->redirectToRoute('lab_tests');
        }

        if ($test === null) {
            $this->addFlash('error', 'Test not found');

            return $this->redirectToRoute('lab_tests');
        }

        return $test;
    }

    private function loadOrder(int $id): LabOrderDTO|RedirectResponse
    {
        try {
            $order = $this->labService->getOrder($id);
        } catch (DbalException $exception) {
            $this->logger->error('Lab order could not be loaded.', ['exception' => $exception, 'order_id' => $id]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return $this->redirectToRoute('lab_worklist');
        }

        if ($order === null) {
            $this->addFlash('error', 'Lab order not found');

            return $this->redirectToRoute('lab_worklist');
        }

        return $order;
    }

    /** The consultation's lab order (empty header when no test has been ordered yet). */
    private function loadConsultationOrder(int $consultationId): LabOrderDTO|RedirectResponse
    {
        try {
            $order = $this->labService->getOrderForConsultation($consultationId);
        } catch (DbalException $exception) {
            $this->logger->error('Consultation could not be loaded.', ['exception' => $exception, 'consultation_id' => $consultationId]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return $this->redirectToRoute('opc_dashboard');
        }

        if ($order === null) {
            $this->addFlash('error', 'Consultation not found');

            return $this->redirectToRoute('opc_dashboard');
        }

        return $order;
    }

    /**
     * @param array<string, string> $errors
     */
    private function renderTestForm(LabTestDTO $test, ?int $testId, array $errors = [], int $status = Response::HTTP_OK, ?string $formError = null): Response
    {
        return $this->render('lab/test_form.html.twig', [
            'test' => $test,
            'test_id' => $testId,
            'categories' => LabService::CATEGORIES,
            'sample_types' => LabService::SAMPLE_TYPES,
            'result_types' => LabService::RESULT_TYPES,
            'errors' => $errors,
            'form_error' => $formError,
        ], new Response('', $status));
    }

    /**
     * @param array<string, string> $typed
     * @param array<string, string> $errors
     */
    private function renderOrderTests(
        LabOrderDTO $order,
        string $search,
        ?int $selectedId,
        array $typed = [],
        array $errors = [],
        int $status = Response::HTTP_OK,
        ?string $formError = null
    ): Response {
        $results = [];
        $selected = null;

        if ($order->canEdit()) {
            try {
                $results = $this->labService->searchOrderable($search);
                $selected = $selectedId !== null ? $this->labService->getTest($selectedId) : null;
            } catch (DbalException $exception) {
                $this->logger->error('Tests could not be searched.', ['exception' => $exception]);
                $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);
            }
            if ($selected !== null && !$selected->isActive) {
                $selected = null;
            }
        }

        return $this->render('lab/order_tests.html.twig', [
            'order' => $order,
            'search' => $search,
            'results' => $results,
            'selected' => $selected,
            'priorities' => LabService::PRIORITIES,
            'typed' => $typed + ['test_id' => '', 'priority' => 'Routine', 'clinical_notes' => ''],
            'errors' => $errors,
            'form_error' => $formError,
        ], new Response('', $status));
    }
}