<?php

namespace App\Controller;

use App\Exception\AppException;
use App\Form\SortingMethodType;
use App\Service\SortingService;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

class SortingController extends AbstractController
{
    /**
     * @throws Exception
     */
    #[Route('/sorting-methods/{sortingMethodName}-sort', name: 'app_sortingMethods', methods: ['GET', 'POST'])]
    public function mergeSort(string $sortingMethodName, Request $request, SortingService $sortingService): Response
    {
        try {
            if (!in_array($sortingMethodName, $sortingService->getSortingMethodsArray())) {
                throw new NotFoundHttpException("Ruta aleasă nu există!");
            }

            $form = $this->createForm(SortingMethodType::class);
            $form->handleRequest($request);

            if ($form->isSubmitted()) {
                if (!$form->isValid()) {
                    $errorMessages = [];

                    foreach ($form->get('arrayToSort')->getErrors(true, false) as $error) {
                        $errorMessages[] = $error->getMessage();
                    }

                    throw new AppException(implode(' ', $errorMessages));
                }

                $array = $form->get('arrayToSort')->getData();
                $array = explode(',', preg_replace('/\s+/', '', $array));

                $sortedArray = $sortingService->sort($array, $sortingMethodName);

                return $this->render('sortingMethods/results.html.twig', [
                    'sortedArray' => implode("\n", $sortedArray),
                    'sortingMethodName' => ucfirst($sortingMethodName)
                ]);
            }

            return $this->render('sortingMethods/index.html.twig', [
                'form' => $form->createView(),
                'sortingMethodName' => ucfirst($sortingMethodName)
            ]);
        } catch (NotFoundHttpException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('app_dashboard_get');
        } catch (Exception $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_sortingMethods', [
                'sortingMethodName' => $sortingMethodName,
            ]);
        }
    }
}