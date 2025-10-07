<?php

namespace App\Controller;

use App\Entity\DoctorProfile;
use App\Entity\Appointment;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AppointmentController extends AbstractController
{
    #[Route('/prendre-rdv', name: 'appointment_specialties')]
    public function specialties(EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PATIENT');

        // Get distinct specialties from doctor profiles
        $qb = $em->getRepository(DoctorProfile::class)->createQueryBuilder('d')
            ->select('d.specialty')
            ->groupBy('d.specialty')
            ->orderBy('d.specialty', 'ASC');

        $specialties = array_map(function($row) { return $row['specialty']; }, $qb->getQuery()->getArrayResult());

        return $this->render('appointment/specialties.html.twig', [
            'specialties' => $specialties,
        ]);
    }

    #[Route('/doctors/{specialty}', name: 'appointment_doctors')]
    public function doctors(string $specialty, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PATIENT');

        $doctors = $em->getRepository(DoctorProfile::class)->findBy(['specialty' => $specialty]);

        return $this->render('appointment/doctors.html.twig', [
            'specialty' => $specialty,
            'doctors' => $doctors,
        ]);
    }

    #[Route('/book/{id}', name: 'appointment_book')]
    public function book(DoctorProfile $id, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PATIENT');

        $doctor = $id;

        // For simplicity, we'll show a week calendar and accept a datetime parameter
        if ($request->isMethod('POST')) {
            $date = $request->request->get('date');
            $time = $request->request->get('time');

            if ($date && $time) {
                $start = new \DateTime($date . ' ' . $time);
                $end = (clone $start)->modify('+30 minutes');

                $appointment = new Appointment();
                $appointment->setDoctor($doctor);
                $appointment->setPatient($this->getUser());
                $appointment->setStartAt($start);
                $appointment->setEndAt($end);

                $em->persist($appointment);
                $em->flush();

                return $this->redirectToRoute('appointment_confirm', ['id' => $appointment->getId()]);
            }
        }

        return $this->render('appointment/book.html.twig', [
            'doctor' => $doctor,
        ]);
    }

    #[Route('/appointment/confirm/{id}', name: 'appointment_confirm')]
    public function confirm(Appointment $id): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PATIENT');

        $appointment = $id;

        return $this->render('appointment/confirm.html.twig', [
            'appointment' => $appointment,
        ]);
    }

    #[Route('/doctor/calendar', name: 'doctor_calendar')]
    public function doctorCalendar(EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_DOCTOR');

        // find doctor profile for current user
        $doctor = $em->getRepository(DoctorProfile::class)->findOneBy(['user' => $this->getUser()]);

        $appointments = [];
        if ($doctor) {
            $appointments = $em->getRepository(Appointment::class)->findBy(['doctor' => $doctor]);
        }

        return $this->render('appointment/doctor_calendar.html.twig', [
            'appointments' => $appointments,
        ]);
    }
}
