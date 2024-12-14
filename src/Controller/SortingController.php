<?php

namespace App\Controller;

use App\Form\SortingMethodType;
use App\Service\MergeSortService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SortingController extends AbstractController
{
    #[Route('/sorting-methods/merge-sort', name: 'app_sortingMethods_mergeSort', methods: ['GET', 'POST'])]
    public function mergeSort(Request $request, MergeSortService $mergeSort): Response
    {
        $form = $this->createForm(SortingMethodType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $array = $form->get('arrayToSort')->getData();

            $array = explode(',', preg_replace('/\s+/', '', $array));

            $sortedArray = $mergeSort->mergeSort($array);
        
            return $this->render('sortingMethods/results.html.twig', [
                'sortedArray' => implode(', ', $sortedArray),
            ]);
        }

        return $this->render('sortingMethods/index.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
