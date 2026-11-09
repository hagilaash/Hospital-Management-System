<?php

namespace App\Controller;

use App\DTO\PagedListDTO;
use App\DTO\RCMCaseDTO;
use App\DTO\RCMSummaryDTO;
use App\Service\AccessControlService;
use App\Service\AuthSessionService;
use App\Service\RCMService;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * HTTP layer for Revenue Cycle Management: Pending queue -> Review -> Finalize -> Completed.
 *
 * Authentication is enforced for every route by AuthenticationListener. Each route
 * also declares the permission it needs (defaults: _permission): rcm.view to look,
 * rcm.finalize to finalize.
 *
 * Finalizing is a POST protected by a CSRF token (id: rcm_finalize_{billId}).
 * Database failures are caught, logged and shown as a generic message; no SQL error
 * text reaches the user.
 */
class RCMController extends AbstractController
{
    private const MESSAGE_GENERIC_ERROR = 'Something went wrong while accessing RCM records. Please try again.';
    private const MESSAGE_CSRF_ERROR = 'Your session has expired. Please try again.';
    private const MESSAGE_NOT_FOUND = 'Case not found';

    public function __construct(
        private RCMService $rcmService,
        private AuthSessionService $authSession,
        private LoggerInterface $logger,
    ) {
    }

    // ------------------------------------------------------------------
    // Overview and dashboard
    // ------------------------------------------------------------------

    #[Route(
        '/rcm',
        name: 'rcm_index',
        methods: ['GET'],
        defaults: ['_permission' => AccessControlService::RCM_VIEW]
    )]
    public function index(): Response
    {
        try {
            $pending = $this->rcmService->getPendingPreview();
        } catch (DbalException $exception) {
            $this->logger->error('RCM queue preview could not be loaded.', ['exception' => $exception]);
            $pending = [];
        }

        return $this->render('rcm/index.html.twig', [
            'summary' => $this->loadSummary(),
            'pending' => $pending,
        ]);
    }

    #[Route(
        '/rcm/dashboard',
        name: 'rcm_dashboard',
        methods: ['GET'],
        defaults: ['_permission' => AccessControlService::RCM_VIEW]
    )]
    public function dashboard(): Response
    {
        return $this->render('rcm/dashboard.html.twig', ['summary' => $this->loadSummary()]);
    }

    // ------------------------------------------------------------------
    // Pending queue and completed list
    // ------------------------------------------------------------------

    #[Route(
        '/rcm/pending',
        name: 'rcm_pending',
        methods: ['GET'],
        defaults: ['_permission' => AccessControlService::RCM_VIEW]
    )]
    public function pending(Request $request): Response
    {
        $query = $request->query;

        try {
            $list = $this->rcmService->getPending(
                $query->getString('search'),
                $query->getString('date_from'),
                $query->getString('date_to'),
                $query->getInt('page', 1)
            );
        } catch (DbalException $exception) {
            $this->logger->error('RCM pending queue could not be loaded.', ['exception' => $exception]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            $list = PagedListDTO::empty(RCMService::PER_PAGE, ['search' => null, 'date_from' => null, 'date_to' => null]);
        }

        return $this->render('rcm/list.html.twig', ['list' => $list]);
    }

    #[Route(
        '/rcm/completed',
        name: 'rcm_completed',
        methods: ['GET'],
        defaults: ['_permission' => AccessControlService::RCM_VIEW]
    )]
    public function completed(Request $request): Response
    {
        $query = $request->query;

        try {
            $list = $this->rcmService->getCompleted(
                $query->getString('search'),
                $query->getString('date_from'),
                $query->getString('date_to'),
                $query->getInt('page', 1)
            );
        } catch (DbalException $exception) {
            $this->logger->error('RCM completed list could not be loaded.', ['exception' => $exception]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            $list = PagedListDTO::empty(RCMService::PER_PAGE, ['search' => null, 'date_from' => null, 'date_to' => null]);
        }

        return $this->render('rcm/completed.html.twig', ['list' => $list]);
    }

    // ------------------------------------------------------------------
    // Review one case, finalize it
    // ------------------------------------------------------------------

    /** {id} is the BILL id: one bill is one RCM case. */
    #[Route(
        '/rcm/{id}',
        name: 'rcm_show',
        requirements: ['id' => '\d{1,9}'],
        methods: ['GET'],
        defaults: ['_permission' => AccessControlService::RCM_VIEW]
    )]
    public function show(int $id): Response
    {
        $case = $this->loadCase($id);
        if ($case instanceof Response) {
            return $case;
        }

        return $this->renderCase($case);
    }

    #[Route(
        '/rcm/{id}/finalize',
        name: 'rcm_finalize',
        requirements: ['id' => '\d{1,9}'],
        methods: ['POST'],
        defaults: ['_permission' => AccessControlService::RCM_FINALIZE]
    )]
    public function finalize(int $id, Request $request): Response
    {
        $case = $this->loadCase($id);
        if ($case instanceof Response) {
            return $case;
        }

        $remarks = $request->request->getString('remarks');

        if (!$this->isCsrfTokenValid('rcm_finalize_'.$id, $request->request->getString('_token'))) {
            return $this->renderCase($case, $remarks, [], Response::HTTP_BAD_REQUEST, self::MESSAGE_CSRF_ERROR);
        }

        try {
            $result = $this->rcmService->finalize($id, $remarks, $this->currentUsername());
        } catch (DbalException $exception) {
            $this->logger->error('RCM finalization failed.', ['exception' => $exception, 'bill_id' => $id]);

            return $this->renderCase($case, $remarks, [], Response::HTTP_SERVICE_UNAVAILABLE, self::MESSAGE_GENERIC_ERROR);
        }

        if ($result['success']) {
            $this->addFlash('success', sprintf('%s. %s', $result['message'], $case->patientName));
            if (null !== $result['visit_note']) {
                $this->addFlash('error', $result['visit_note'].'. Please check the visit in Front Desk.');
            }

            return $this->redirectToRoute('rcm_show', ['id' => $id]);
        }

        // Someone finalized it first (or this is a repeated click): show the record, not a form.
        if ($result['already_finalized']) {
            $this->addFlash('error', $result['message']);

            return $this->redirectToRoute('rcm_show', ['id' => $id]);
        }

        $status = [] !== $result['errors'] ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_CONFLICT;

        return $this->renderCase($case, $remarks, $result['errors'], $status, $result['message']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function currentUsername(): string
    {
        return $this->authSession->getUsername() ?? '';
    }

    /** A failure here shows zeros and a message instead of taking the page down. */
    private function loadSummary(): RCMSummaryDTO
    {
        try {
            return $this->rcmService->getSummary();
        } catch (DbalException $exception) {
            $this->logger->error('RCM summary could not be loaded.', ['exception' => $exception]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return RCMSummaryDTO::empty();
        }
    }

    /** The case, or a redirect (with a flash message) when it does not exist or the database fails. */
    private function loadCase(int $id): RCMCaseDTO|RedirectResponse
    {
        try {
            $case = $this->rcmService->getCase($id);
        } catch (DbalException $exception) {
            $this->logger->error('RCM case could not be loaded.', ['exception' => $exception, 'bill_id' => $id]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return $this->redirectToRoute('rcm_pending');
        }

        if (null === $case) {
            $this->addFlash('error', self::MESSAGE_NOT_FOUND);

            return $this->redirectToRoute('rcm_pending');
        }

        return $case;
    }

    /**
     * @param array<string, string> $errors
     */
    private function renderCase(
        RCMCaseDTO $case,
        string $remarks = '',
        array $errors = [],
        int $status = Response::HTTP_OK,
        ?string $formError = null,
    ): Response {
        return $this->render('rcm/show.html.twig', [
            'case' => $case,
            'remarks' => $remarks,
            'errors' => $errors,
            'form_error' => $formError,
        ], new Response('', $status));
    }
}
