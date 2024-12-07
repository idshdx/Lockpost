<?php

namespace App\Controller;

use App\Entity\University;
use App\Form\UniversityType;
use App\Repository\UniversityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/university')]
final class UniversityController extends AbstractController
{
    #[Route(name: 'app_university_index', methods: ['GET'])]
    public function index(UniversityRepository $universityRepository): Response
    {
        return $this->render('university/index.html.twig', [
            'universities' => $universityRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_university_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $university = new University();
        $form = $this->createForm(UniversityType::class, $university);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($university);
            $entityManager->flush();

            return $this->redirectToRoute('app_university_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('university/new.html.twig', [
            'university' => $university,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_university_show', methods: ['GET'])]
    public function show(University $university): Response
    {
        return $this->render('university/show.html.twig', [
            'university' => $university,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_university_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, University $university, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(UniversityType::class, $university);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_university_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('university/edit.html.twig', [
            'university' => $university,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_university_delete', methods: ['POST'])]
    public function delete(Request $request, University $university, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$university->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($university);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_university_index', [], Response::HTTP_SEE_OTHER);
    }
}
