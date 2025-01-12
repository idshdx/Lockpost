<?php

namespace App\Controller;

use App\Exception\AppException;
use App\Form\SearchingMethodType;
use App\Service\SearchingService;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

class SearchingController extends AbstractController
{
    /**
     * @throws AppException
     */
    #[Route('/searching-methods/{searchingMethodName}', name: 'app_searchingMethods', methods: ['GET', 'POST'])]
    public function abstractSearch(string $searchingMethodName, Request $request, SearchingService $searchingService): Response
    {
        $searchingMethodTitle = null;

        try {
            if ($searchingMethodName == 'sequential-search') {
                $searchingMethodTitle = 'Sequential search';
            } elseif ($searchingMethodName == 'binary-search') {
                $searchingMethodTitle = 'Binary search';
            } else {
                throw new NotFoundHttpException("Ruta aleasă nu există!");
            }

            $form = $this->createForm(SearchingMethodType::class);
            $form->handleRequest($request);

            if ($form->isSubmitted()) {
                if (!$form->isValid()) {
                    $errorMessages = [];

                    foreach ($form->get('arrayToSort')->getErrors(true, false) as $error) {
                        $errorMessages[] = $error->getMessage();
                    }

                    throw new AppException(implode(' ', $errorMessages));
                }

                $initialArray = $form->get('array')->getData();
                $needle = (int)$form->get('needle')->getData();
                $array = explode(',', preg_replace('/\s+/', '', $initialArray));

                if ($searchingMethodName == 'sequential-search') {
                    $position = $searchingService->sequentialSearch($array, $needle);
                } else {
                    $sortedArray = $array;
                    sort($sortedArray);

                    if ($array !== $sortedArray) {
                        throw new AppException("Metoda de cautare binara nu functioneaza decat pe siruri sortate crescator!");
                    }

                    $position = $searchingService->binarySearch($array, $needle);
                }

                return $this->render('searchingMethods/results.html.twig', [
                    'searchingMethodTitle' => $searchingMethodTitle,
                    'array' => $initialArray,
                    'needle' => $needle,
                    'position' => $position
                ]);
            }

            return $this->render('searchingMethods/index.html.twig', [
                'form' => $form->createView(),
                'searchingMethodTitle' => $searchingMethodTitle,
            ]);
        } catch (NotFoundHttpException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('app_dashboard_get');
        } catch (Exception $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_searchingMethods', [
                'searchingMethodName' => $searchingMethodName
            ]);
        }
    }
}