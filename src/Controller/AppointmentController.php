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

        $rows = $qb->getQuery()->getArrayResult();
        $specialties = array_map(function($row) { return $row['specialty']; }, $rows);

        // If no specialties in DB yet, provide the requested default list so the page always shows options
        if (empty($specialties)) {
            $specialties = [
                'Cardiologie',
                'ophtalmologie',
                'medecin generaliste',
                'neurologie',
                'psychiatrie',
            ];
        }

        return $this->render('appointment/specialties.html.twig', [
            'specialties' => $specialties,
        ]);
    }

    #[Route('/doctors/{specialty}', name: 'appointment_doctors')]
    public function doctors(string $specialty, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PATIENT');

        $doctors = $em->getRepository(DoctorProfile::class)->findBy(['specialty' => $specialty]);

        // Generate available slots per doctor for the next 7 days
        $slotsByDoctor = [];
        $days = 7;
        $slotMinutes = 30;
        $workStart = 9; // 9:00
        $workEnd = 17; // 17:00

        $now = new \DateTime();
        $endDate = (clone $now)->modify("+$days days");

        foreach ($doctors as $doctor) {
            // load appointments for the period
            $qb = $em->getRepository(Appointment::class)->createQueryBuilder('a')
                ->where('a.doctor = :doc')
                ->andWhere('a.startAt BETWEEN :from AND :to')
                ->setParameter('doc', $doctor)
                ->setParameter('from', $now->format('Y-m-d 00:00:00'))
                ->setParameter('to', $endDate->format('Y-m-d 23:59:59'));

            $appts = $qb->getQuery()->getResult();
            $occupied = [];
            foreach ($appts as $a) {
                $occupied[] = $a->getStartAt()->format('Y-m-d H:i');
            }

            $slots = [];
            $cursor = (clone $now)->setTime($workStart,0,0);
            $lastDay = (clone $now)->modify("+$days days");
            while ($cursor <= $lastDay) {
                $hour = (int)$cursor->format('H');
                if ($hour >= $workStart && $hour < $workEnd) {
                    $slotStart = clone $cursor;
                    $slotKey = $slotStart->format('Y-m-d H:i');
                    if (!in_array($slotKey, $occupied)) {
                        // Only future slots
                        if ($slotStart > new \DateTime()) {
                            $slots[] = ['date' => $slotStart->format('Y-m-d'), 'time' => $slotStart->format('H:i')];
                        }
                    }
                    $cursor->modify("+$slotMinutes minutes");
                } else {
                    // move to next day's start
                    $cursor->modify('+1 day');
                    $cursor->setTime($workStart,0,0);
                }
            }

            $slotsByDoctor[$doctor->getId()] = $slots;
        }

        return $this->render('appointment/doctors.html.twig', [
            'specialty' => $specialty,
            'doctors' => $doctors,
            'slotsByDoctor' => $slotsByDoctor,
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

        // load existing appointments for this doctor to show on the calendar
        $appointments = $em->getRepository(Appointment::class)->findBy(['doctor' => $doctor]);

        // Prefill date/time from query params if present
        $prefillDate = $request->query->get('date');
        $prefillTime = $request->query->get('time');

        return $this->render('appointment/book.html.twig', [
            'doctor' => $doctor,
            'appointments' => $appointments,
            'prefillDate' => $prefillDate,
            'prefillTime' => $prefillTime,
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
