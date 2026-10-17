<?php

namespace App\Controller;

use App\Service\DoctorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DoctorController extends AbstractController
{
    public function __construct(
        private DoctorService $doctorService,
    ) {
    }

    #[Route('/doctors', name: 'doctors', methods: ['GET'])]
    public function doctors(Request $request): Response
    {
        // dd($request->query->all()); exit;
        $page = max(1, $request->query->getInt('page', 1));
        $pageSize = 10;

        $search = trim($request->query->get('search', ''));
        $specialization = trim($request->query->get('specialization', ''));
        $department = trim($request->query->get('department', ''));
        $status = ucfirst(
            strtolower(trim($request->query->get('status', 'Active')))
        );

        if (!in_array($status, ['Active', 'Inactive', 'All'], true)) {
            $status = 'Active';
        }

        $search = '' !== $search ? $search : null;
        $specialization = '' !== $specialization ? $specialization : null;
        $department = '' !== $department ? $department : null;

        $doctors = $this->doctorService->getDoctorsList(
            $page,
            $pageSize,
            $search,
            $specialization,
            $department,
            $status
        );

        $totalDoctors = $this->doctorService->countDoctors(
            $search,
            $specialization,
            $department,
            $status
        );
        $totalPages = (int) ceil($totalDoctors / $pageSize);

        return $this->render('doctor/doctors.html.twig', [
            'doctors' => $doctors,
            'currentPage' => $page,
            'pageSize' => $pageSize,
            'totalDoctors' => $totalDoctors,
            'totalPages' => $totalPages,
            'search' => $search ?? '',
            'selectedSpecialization' => $specialization ?? '',
            'selectedDepartment' => $department ?? '',
            'selectedStatus' => $status,
        ]);
    }

    #[Route('/doctors/new', name: 'doctor_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $data = $request->request->all();

            $result = $this->doctorService->registerDoctor($data);

            if (($result['success'] ?? false) === true
                || ($result['success'] ?? null) === 't'
                || ($result['success'] ?? null) === '1'
            ) {
                $this->addFlash(
                    'success',
                    $result['message'] ?? 'Doctor registered successfully.'
                );

                return $this->redirectToRoute('doctors');
            }

            $this->addFlash(
                'error',
                $result['message'] ?? 'Unable to register doctor.'
            );

            return $this->render('doctor/new.html.twig', [
                'data' => $data,
            ]);
        }

        return $this->render('doctor/new.html.twig', [
            'data' => [],
        ]);
    }
}
