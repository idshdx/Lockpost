<?php

namespace App\Controller;

use App\Form\SortingMethodType;
use App\Service\SortingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SortingController extends AbstractController
{
    /**
     * @throws \Exception
     */
    #[Route('/sorting-methods/{sortingMethodName}-sort', name: 'app_sortingMethods', methods: ['GET', 'POST'])]
    public function mergeSort(string $sortingMethodName, Request $request, SortingService $sortingService): Response
    {
        if (!in_array($sortingMethodName, $sortingService->getSortingMethodsArray())) {
            throw new \Exception("Ruta aleasa nu exista!");
        }

        $form = $this->createForm(SortingMethodType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                $array = $form->get('arrayToSort')->getData();

                $array = explode(',', preg_replace('/\s+/', '', $array));

                $sortedArray = $sortingService->sort($array, $sortingMethodName);

                return $this->render('sortingMethods/results.html.twig', [
                    'sortedArray' => implode(', ', $sortedArray),
                    'sortingMethodName' => ucfirst($sortingMethodName)
                ]);
            } else {
                foreach ($form->get('arrayToSort')->getErrors(true, false) as $error) {
                    $this->addFlash('error', $error->getMessage());
                }
            }
        }

        return $this->render('sortingMethods/index.html.twig', [
            'form' => $form->createView(),
            'sortingMethodName' => ucfirst($sortingMethodName)
        ]);
    }
}
