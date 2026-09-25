<?php

namespace App\Controller;

use App\Service\DoctorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DoctorController extends AbstractController
{
    public function __construct(
        private DoctorService $doctorService
    ) {
    }

    #[Route('/doctors', name: 'doctors', methods: ['GET'])]
    public function doctors(): Response
    {
        $doctors = $this->doctorService->getDoctorsList();

        return $this->render('doctor/doctors.html.twig', [
            'doctors' => $doctors
        ]);
    }
}